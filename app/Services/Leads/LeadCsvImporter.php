<?php

namespace App\Services\Leads;

use App\Actions\Leads\CreateLead;
use App\Enums\MembershipStatus;
use App\Enums\Priority;
use App\Exceptions\DuplicateLeadException;
use App\Models\LeadSource;
use App\Models\PipelineStage;
use App\Models\TenantMembership;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Imports leads from a CSV file into the current tenant, as $actor, through CreateLead (same rules as the form).
 *
 * - The header row names the columns (any order, case-insensitive; common aliases accepted). Unknown columns are ignored.
 * - Every row is validated on its own; a bad row is reported with its line number and never stops the others.
 * - Possible duplicates (same phone or e-mail as an existing lead, or as an earlier row of the same file) are skipped
 *   unless $allowDuplicates. Existing leads are never modified.
 * - A dry run validates everything and reports what WOULD happen, without writing.
 */
class LeadCsvImporter
{
    /** Accepted header names => field. */
    private const ALIASES = [
        'name' => 'full_name', 'full_name' => 'full_name', 'full name' => 'full_name', 'lead' => 'full_name',
        'email' => 'email', 'e-mail' => 'email', 'email_address' => 'email',
        'phone' => 'phone', 'phone_e164' => 'phone', 'mobile' => 'phone', 'phone_number' => 'phone', 'whatsapp' => 'phone',
        'company' => 'company_name', 'company_name' => 'company_name', 'organisation' => 'company_name', 'organization' => 'company_name',
        'source' => 'source', 'lead_source' => 'source',
        'stage' => 'stage', 'pipeline_stage' => 'stage',
        'owner_email' => 'owner_email', 'owner' => 'owner_email', 'assigned_to' => 'owner_email',
        'priority' => 'priority',
        'value' => 'estimated_value', 'estimated_value' => 'estimated_value', 'deal_value' => 'estimated_value',
        'notes' => 'notes', 'note' => 'notes',
    ];

    public const MAX_REPORTED_ERRORS = 100;

    public function __construct(private CreateLead $createLead) {}

    /**
     * @return array{total: int, created: int, duplicates: int, failed: int, dry_run: bool, errors: list<array{line: int, messages: list<string>}>}
     *
     * @throws ValidationException when the file itself is unusable (no header, no name column, too many rows)
     */
    public function run(User $actor, string $path, int $defaultSourceId, bool $allowDuplicates = false, bool $dryRun = false): array
    {
        $handle = fopen($path, 'r');
        if ($handle === false) {
            throw new RuntimeException('Cannot read the uploaded file.');
        }

        try {
            $columns = $this->header($handle);

            // Read every row first (files are size-capped), so an oversized file is refused before anything is written.
            $rows = [];
            $max = (int) config('crm.import_max_rows', 2000);
            $line = 1;
            while (($cells = fgetcsv($handle, escape: '')) !== false) {
                $line++;
                if ($cells === [null] || implode('', array_map(fn ($c) => trim((string) $c), $cells)) === '') {
                    continue; // blank line
                }
                if (count($rows) >= $max) {
                    throw ValidationException::withMessages(['file' => "The file has more than {$max} rows. Split it into smaller files."]);
                }
                $rows[$line] = $cells;
            }

            $lookups = $this->lookups();
            $result = ['total' => count($rows), 'created' => 0, 'duplicates' => 0, 'failed' => 0, 'dry_run' => $dryRun, 'errors' => []];
            $seen = [];

            foreach ($rows as $line => $cells) {
                $row = [];
                foreach ($columns as $index => $field) {
                    $row[$field] = isset($cells[$index]) ? trim((string) $cells[$index]) : '';
                }

                [$data, $errors] = $this->prepare($row, $lookups, $defaultSourceId);
                if ($errors !== []) {
                    $this->fail($result, $line, $errors);

                    continue;
                }

                // Same person twice in one file: the second row is a duplicate of the first.
                $keys = array_values(array_filter([$data['phone_e164'] ? 'p:'.$data['phone_e164'] : null, $data['email'] ? 'e:'.$data['email'] : null]));
                if (! $allowDuplicates && array_intersect($keys, array_keys($seen)) !== []) {
                    $result['duplicates']++;

                    continue;
                }

                if ($dryRun) {
                    if (! $allowDuplicates && DuplicateFinder::find($data['phone_e164'], $data['email'])->isNotEmpty()) {
                        $result['duplicates']++;
                    } else {
                        $result['created']++;
                    }
                } else {
                    try {
                        ($this->createLead)($actor, $data, $allowDuplicates);
                        $result['created']++;
                    } catch (DuplicateLeadException) {
                        $result['duplicates']++;
                    } catch (ValidationException $e) {
                        $this->fail($result, $line, array_values(array_merge(...array_values($e->errors()))));

                        continue;
                    }
                }

                foreach ($keys as $key) {
                    $seen[$key] = true;
                }
            }

            if ($result['total'] === 0) {
                throw ValidationException::withMessages(['file' => 'The file has a header row but no leads.']);
            }

            return $result;
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param  resource  $handle
     * @return array<int, string> column index => field
     */
    private function header($handle): array
    {
        $header = fgetcsv($handle, escape: '');
        if ($header === false || $header === [null]) {
            throw ValidationException::withMessages(['file' => 'The file is empty.']);
        }

        $columns = [];
        foreach ($header as $index => $name) {
            $name = strtolower(trim((string) $name));
            if ($index === 0) {
                $name = ltrim($name, "\xEF\xBB\xBF"); // UTF-8 BOM
            }
            $field = self::ALIASES[$name] ?? self::ALIASES[str_replace(' ', '_', $name)] ?? null;
            if ($field !== null && ! in_array($field, $columns, true)) {
                $columns[$index] = $field;
            }
        }

        if (! in_array('full_name', $columns, true)) {
            throw ValidationException::withMessages(['file' => 'The first row must be a header with at least a "name" column (and "phone" or "email").']);
        }

        return $columns;
    }

    /** @return array{sources: array<string, int>, stages: array<string, int>, owners: array<string, int>} */
    private function lookups(): array
    {
        $byNameAndKey = function ($models): array {
            $map = [];
            foreach ($models as $m) {
                $map[mb_strtolower($m->name)] = (int) $m->id;
                $map[mb_strtolower($m->key)] = (int) $m->id;
            }

            return $map;
        };

        $owners = [];
        foreach (TenantMembership::query()->where('status', MembershipStatus::Active->value)->with('user:id,email')->get() as $membership) {
            if ($membership->user) {
                $owners[mb_strtolower($membership->user->email)] = (int) $membership->user_id;
            }
        }

        return [
            'sources' => $byNameAndKey(LeadSource::query()->where('is_active', true)->get(['id', 'name', 'key'])),
            'stages' => $byNameAndKey(PipelineStage::query()->where('is_active', true)->get(['id', 'name', 'key'])),
            'owners' => $owners,
        ];
    }

    /**
     * @param  array<string, string>  $row
     * @param  array{sources: array<string, int>, stages: array<string, int>, owners: array<string, int>}  $lookups
     * @return array{0: array<string, mixed>, 1: list<string>}
     */
    private function prepare(array $row, array $lookups, int $defaultSourceId): array
    {
        $errors = [];
        $value = fn (string $field): ?string => ($row[$field] ?? '') === '' ? null : $row[$field];

        $validator = Validator::make([
            'full_name' => $value('full_name'),
            'email' => $value('email') !== null ? mb_strtolower((string) $value('email')) : null,
            'company_name' => $value('company_name'),
            'priority' => $value('priority') !== null ? mb_strtolower((string) $value('priority')) : null,
            'estimated_value' => $value('estimated_value') !== null ? str_replace([',', ' '], '', (string) $value('estimated_value')) : null,
            'notes' => $value('notes'),
        ], [
            'full_name' => ['required', 'string', 'min:2', 'max:255'],
            'email' => ['nullable', 'string', 'email:rfc', 'max:255'],
            'company_name' => ['nullable', 'string', 'max:255'],
            'priority' => ['nullable', Rule::enum(Priority::class)],
            'estimated_value' => ['nullable', 'numeric', 'min:0', 'max:999999999999.99', 'decimal:0,2'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ], [], ['full_name' => 'name', 'estimated_value' => 'value']);

        if ($validator->fails()) {
            $errors = array_values($validator->errors()->all());
        }
        $data = $validator->getData();

        $phone = $value('phone');
        $data['phone_e164'] = $phone !== null ? PhoneNormalizer::normalize($phone) : null;
        if ($phone !== null && $data['phone_e164'] === null) {
            $errors[] = "\"{$phone}\" is not a valid phone number (add the country code if it is not an Indian number).";
        }
        if ($phone === null && $data['email'] === null) {
            $errors[] = 'A phone number or an e-mail address is required.';
        }

        $source = $value('source');
        $data['lead_source_id'] = $source === null ? $defaultSourceId : ($lookups['sources'][mb_strtolower($source)] ?? null);
        if ($data['lead_source_id'] === null) {
            $errors[] = "Unknown source \"{$source}\".";
        }

        $stage = $value('stage');
        if ($stage !== null) {
            $data['pipeline_stage_id'] = $lookups['stages'][mb_strtolower($stage)] ?? null;
            if ($data['pipeline_stage_id'] === null) {
                $errors[] = "Unknown or inactive stage \"{$stage}\".";
            }
        }

        $owner = $value('owner_email');
        if ($owner !== null) {
            $data['owner_id'] = $lookups['owners'][mb_strtolower($owner)] ?? null;
            if ($data['owner_id'] === null) {
                $errors[] = "\"{$owner}\" is not an active member of this workspace.";
            }
        }

        $data['priority'] ??= Priority::Medium->value;

        return [$data, $errors];
    }

    /**
     * @param  array{total: int, created: int, duplicates: int, failed: int, dry_run: bool, errors: list<array{line: int, messages: list<string>}>}  $result
     * @param  list<string>  $messages
     */
    private function fail(array &$result, int $line, array $messages): void
    {
        $result['failed']++;
        if (count($result['errors']) < self::MAX_REPORTED_ERRORS) {
            $result['errors'][] = ['line' => $line, 'messages' => $messages];
        }
    }
}
