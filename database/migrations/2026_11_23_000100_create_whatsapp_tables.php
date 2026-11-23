<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WhatsApp Cloud API (additive only). Every cross-table reference is tenant-composite, so the database itself rejects
 * a message, conversation or contact that points at another tenant's rows.
 *
 * Provider asset ids (phone_number_id, wamid) are GLOBALLY unique: one WhatsApp number belongs to exactly one tenant,
 * which is what lets an incoming webhook be routed to a tenant without trusting anything else in the payload.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_accounts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->char('public_id', 26)->unique();
            $table->string('waba_id', 64);
            $table->string('phone_number_id', 64)->unique();
            $table->string('display_phone_number', 32)->nullable();
            $table->string('verified_name')->nullable();
            $table->text('access_token')->nullable(); // encrypted cast; cleared on disconnect
            // active | reauthorization_required | disconnected
            $table->string('status', 32);
            $table->boolean('auto_create_leads')->default(true);
            $table->unsignedBigInteger('default_owner_id')->nullable();
            $table->string('subscription_status', 24)->default('pending'); // pending | subscribed | failed
            $table->dateTime('last_validated_at')->nullable();
            $table->dateTime('templates_synced_at')->nullable();
            $table->dateTime('last_webhook_at')->nullable();
            $table->string('last_error_code', 64)->nullable();
            $table->string('last_error_summary', 255)->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->unique(['tenant_id', 'id']);
            $table->foreign(['tenant_id', 'created_by'])->references(['tenant_id', 'user_id'])->on('tenant_memberships');
            $table->foreign(['tenant_id', 'default_owner_id'])->references(['tenant_id', 'user_id'])->on('tenant_memberships');
        });

        Schema::create('whatsapp_contacts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->string('wa_id', 32);
            $table->string('phone_e164', 20)->nullable();
            $table->string('profile_name')->nullable();
            $table->unsignedBigInteger('lead_id')->nullable();
            $table->dateTime('opted_out_at')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->unique(['tenant_id', 'id']);
            // The same person can message two different businesses: a contact is per tenant.
            $table->unique(['tenant_id', 'wa_id']);
            $table->index(['tenant_id', 'phone_e164']);
            $table->foreign(['tenant_id', 'lead_id'])->references(['tenant_id', 'id'])->on('leads'); // leads are archived, never hard-deleted
        });

        Schema::create('whatsapp_conversations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->char('public_id', 26)->unique();
            $table->unsignedBigInteger('whatsapp_account_id');
            $table->unsignedBigInteger('whatsapp_contact_id');
            $table->unsignedBigInteger('lead_id')->nullable();
            // linked | unlinked | ambiguous (several leads share the number: a human must pick)
            $table->string('link_status', 16)->default('unlinked');
            $table->dateTime('last_message_at')->nullable();
            $table->dateTime('last_inbound_at')->nullable(); // drives the 24-hour customer-service window
            $table->unsignedInteger('unread_count')->default(0);
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'whatsapp_account_id', 'whatsapp_contact_id'], 'wa_conversations_account_contact_unique');
            $table->index(['tenant_id', 'last_message_at']);
            $table->index(['tenant_id', 'lead_id']);
            $table->foreign(['tenant_id', 'whatsapp_account_id'])->references(['tenant_id', 'id'])->on('whatsapp_accounts')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'whatsapp_contact_id'])->references(['tenant_id', 'id'])->on('whatsapp_contacts')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'lead_id'])->references(['tenant_id', 'id'])->on('leads'); // leads are archived, never hard-deleted
        });

        Schema::create('whatsapp_messages', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->char('public_id', 26)->unique();
            $table->unsignedBigInteger('whatsapp_conversation_id');
            $table->string('direction', 3); // in | out
            // Meta's message id. Globally unique: inbound redeliveries collapse on it.
            $table->string('wamid', 191)->nullable()->unique();
            $table->string('kind', 16); // text | template | image | document | audio | video | sticker | location | contacts | interactive | button | reaction | unsupported
            $table->text('body')->nullable(); // encrypted cast
            $table->string('template_name', 512)->nullable();
            $table->string('template_language', 16)->nullable();
            // queued | sent | delivered | read | failed | received
            $table->string('status', 16);
            $table->string('error_code', 64)->nullable();
            $table->string('error_title', 255)->nullable();
            $table->unsignedBigInteger('sent_by')->nullable();
            $table->dateTime('provider_timestamp')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'whatsapp_conversation_id', 'id'], 'wa_messages_conversation_index');
            $table->index(['status', 'updated_at']);
            $table->foreign(['tenant_id', 'whatsapp_conversation_id'])->references(['tenant_id', 'id'])->on('whatsapp_conversations')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'sent_by'])->references(['tenant_id', 'user_id'])->on('tenant_memberships');
        });

        Schema::create('whatsapp_templates', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('whatsapp_account_id');
            $table->string('name', 512);
            $table->string('language', 16);
            $table->string('category', 32)->nullable();
            $table->string('status', 32);
            $table->json('components')->nullable();
            $table->unsignedSmallInteger('body_param_count')->default(0);
            $table->dateTime('synced_at');
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'whatsapp_account_id', 'name', 'language'], 'wa_templates_account_name_lang_unique');
            $table->foreign(['tenant_id', 'whatsapp_account_id'])->references(['tenant_id', 'id'])->on('whatsapp_accounts')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_templates');
        Schema::dropIfExists('whatsapp_messages');
        Schema::dropIfExists('whatsapp_conversations');
        Schema::dropIfExists('whatsapp_contacts');
        Schema::dropIfExists('whatsapp_accounts');
    }
};
