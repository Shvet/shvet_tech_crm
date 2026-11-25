# Meta app setup: policy URLs, use cases and permissions

What to enter in the Meta App Dashboard for the CRM's platform Meta app, and why each use case and permission is
needed. Based on the features actually in the code (Lead Ads in `app/Services/Meta`, WhatsApp in
`app/Services/WhatsApp`). Checked against Meta's developer docs on 2026-11-25 where noted; Meta renames use cases and
permissions often, so re-check in the dashboard when you submit.

## 1. Policy pages and callback URLs

Replace `<APP_URL>` with the production `APP_URL` (must be public HTTPS). Set `LEGAL_CONTACT_EMAIL` (and optionally
`LEGAL_COMPANY_NAME`, `LEGAL_ADDRESS`) first: reviewers check that the policies name a real, monitored contact, and
`crm:preflight` warns while it is empty. The pages are plain server-rendered HTML, so Meta's crawler sees the full text,
and they are linked from the footer of every page in the app (sign-in pages included).

| Meta App Dashboard field | Where | URL |
|---|---|---|
| Privacy Policy URL | App settings > Basic | `<APP_URL>/privacy` |
| Terms of Service URL | App settings > Basic | `<APP_URL>/terms` |
| User data deletion: **Data deletion callback URL** (recommended) | App settings > Basic | `<APP_URL>/webhooks/meta/data-deletion` |
| User data deletion: instructions URL (alternative to the callback) | App settings > Basic | `<APP_URL>/data-deletion` |
| Deauthorize callback URL | Facebook Login for Business > Settings | `<APP_URL>/webhooks/meta/deauthorize` |
| Valid OAuth Redirect URI (existing) | Facebook Login for Business > Settings | `<APP_URL>/integrations/meta/callback` |
| Webhooks, Page object, field `leadgen` (existing) | Webhooks | `<APP_URL>/webhooks/meta` |
| WhatsApp webhook, field `messages` (existing) | WhatsApp > Configuration | `<APP_URL>/webhooks/whatsapp` |

Also required in App settings > Basic: app icon (1024x1024), category (Business and pages), contact e-mail, and the
business that owns the app (Business Verification must be complete for Advanced Access).

### What the two callbacks do

Both accept Meta's `signed_request` (HMAC-SHA256 with `META_APP_SECRET`; anything unsigned or wrongly signed gets 403)
and never call Meta back.

- **Deauthorize** (the person removed the app in Facebook settings): every workspace connection authorized by that
  Facebook user is disconnected, and its Page tokens and connected Pages are deleted.
- **Data deletion**: the same, and the person's app-scoped Facebook user id and name are erased. The response is
  `{ "url": "<APP_URL>/data-deletion/status?code=…", "confirmation_code": "…" }`, as Meta requires; the status page
  shows the result. Only a keyed hash of the user id is kept, to tie requests together.
- Imported leads are kept: they are the advertiser's (workspace's) customer records, not the authorizing admin's data.
  People who filled in a lead form or messaged on WhatsApp are told on `/data-deletion` to ask the business or e-mail us.

Code: `app/Http/Controllers/Meta/MetaDataDeletionController.php`, `app/Actions/Meta/HandleMetaDataRequest.php`,
`app/Services/Meta/SignedRequest.php`; pages in `resources/views/legal`.

## 2. Use cases to add to the app

| Use case (App Dashboard name) | Needed? | Why |
|---|---|---|
| **Capture & manage ad leads with Marketing API** | Yes | Facebook and Instagram Lead Ads arrive in the CRM as leads. Instagram lead ads come through the linked Facebook Page, so no Instagram use case is needed for them. Adds Facebook Login for Business and Webhooks. |
| **Connect with customers through WhatsApp** | Yes | The WhatsApp shared inbox: receive messages, reply in the 24-hour window, send approved templates. |
| Engage with customers on Messenger | Not yet | The CRM has no Messenger inbox. Add it only if Messenger conversations are built. |
| Instagram messaging (Instagram API use case) | Not yet | The CRM does not read or send Instagram DMs. Add it only if that is built. |
| Measure ad performance data with Marketing API | Not yet | Campaign spend/insights reporting is planned (INTEGRATION_REQUIREMENTS.md §6) but not built. |

## 3. Permissions, with the reason to give in App Review

### Capture & manage ad leads (requested at "Connect Facebook" via `META_OAUTH_SCOPES`)

| Permission | Requested by the CRM | Reason for App Review |
|---|---|---|
| `pages_show_list` | Yes (required) | Show the admin the Facebook Pages they manage so they can choose which Pages send leads to the CRM (`GET /me/accounts`). |
| `pages_read_engagement` | Yes | Read basic Page details (name, id) returned with the Page tokens for the Pages the admin connects. |
| `pages_manage_metadata` | Yes (required) | Subscribe our app to the Page's `leadgen` webhook so new leads reach the CRM in real time (`POST /{page-id}/subscribed_apps`), and unsubscribe on disconnect. |
| `pages_manage_ads` | Yes (required) | List the Page's lead forms so the admin can map form questions to CRM fields (`GET /{page-id}/leadgen_forms`). |
| `leads_retrieval` | Yes (required) | Fetch the answers of each new lead (`GET /{leadgen-id}`); the webhook only carries ids. |
| `public_profile` | Automatic | Identify the admin who connected (id and name), shown on the Integrations page. |
| `ads_management`, `ads_read`, `business_management` | No | Meta's dashboard lists these as part of this use case (per its docs on 2026-11-25), but the code does not request them. If review asks, explain they are unused, or add them to `META_OAUTH_SCOPES` only if Meta refuses lead/form access without them (see the `pages_manage_ads` note in PHASE_3_META_LEAD_ADS.md §1.2). |

### Connect with customers through WhatsApp (on the System User token a workspace enters)

| Permission | Reason for App Review |
|---|---|
| `whatsapp_business_messaging` | Send the replies and template messages agents write in the shared inbox, receive incoming messages and delivery statuses, and download incoming media (`POST /{phone-number-id}/messages`). |
| `whatsapp_business_management` | Read the WhatsApp Business Account and phone number to verify the connection, subscribe the app to the account's webhooks (`POST /{waba-id}/subscribed_apps`) and sync approved message templates (`GET /{waba-id}/message_templates`). |
| `business_management` | Only if onboarding moves to Embedded Signup (other businesses' WABAs). Today each workspace pastes its own System User token, so it is not used. |

Note: a workspace that is not Shvet Technologies' own business can only generate a System User token for this app once
the app is approved for Advanced Access (or via Embedded Signup as a Tech Provider). For go-live with outside customers,
plan for Advanced Access on both WhatsApp permissions.

## 4. App Review submission checklist

1. Business Verification complete for the business that owns the app.
2. Policy URLs and the data deletion callback from section 1 entered and loading over HTTPS.
3. For each permission above: the reason text from the table, plus a screencast showing the flow in the CRM
   (Integrations > Connect Facebook > choose Pages > map a form > a test lead appearing; WhatsApp > connect number >
   receive and reply to a message, send a template).
4. A test login for the reviewer: a CRM user in a demo workspace, and test Page/WABA access as Meta's instructions ask.
5. Switch the app to Live mode after approval.
