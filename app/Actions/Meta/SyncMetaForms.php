<?php

namespace App\Actions\Meta;

use App\Exceptions\MetaApiException;
use App\Models\MetaFormMapping;
use App\Models\MetaPage;
use App\Services\Meta\GraphClient;

/** Refreshes the Page's lead-form list. New forms appear DISABLED and unmapped; nothing is ever enabled implicitly. */
class SyncMetaForms
{
    public function __construct(private GraphClient $graph) {}

    /** @return int number of forms returned by Meta @throws MetaApiException */
    public function __invoke(MetaPage $page): int
    {
        $forms = $this->graph->leadForms($page->page_id, $page->access_token);

        foreach ($forms as $form) {
            $mapping = MetaFormMapping::query()->firstOrNew(['meta_page_id' => $page->getKey(), 'form_id' => $form['id']]);
            $mapping->fill(['form_name' => $form['name'], 'form_status' => $form['status']]);
            $mapping->save();
        }

        $page->forceFill(['last_sync_at' => now()])->save();

        return count($forms);
    }
}
