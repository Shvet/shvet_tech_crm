@extends('legal.layout')

@section('title', 'Privacy Policy')
@section('description', 'How '.$company.' collects, uses, shares and deletes data in '.$appName.', including data from Facebook, Instagram and WhatsApp.')

@section('content')
    <p>
        This policy explains how {{ $company }} ("we", "us") handles personal data in {{ $appName }} (the "Service"),
        a customer relationship management tool that businesses use to manage sales leads, including leads from
        Facebook and Instagram Lead Ads and conversations on WhatsApp. Questions: @include('legal.contact').
    </p>

    <h2>1. Who is responsible</h2>
    <p>
        Each business that uses the Service (a "Workspace") decides which leads and customers it manages. For that
        customer data, the Workspace is the data controller and we process it on the Workspace's instructions. For the
        accounts of people who sign in to the Service, and for the Facebook connection an administrator authorizes,
        {{ $company }} is the controller.
    </p>

    <h2>2. Data we collect</h2>
    <h3>People who use the Service (Workspace members)</h3>
    <ul>
        <li>Name, e-mail address, optional phone number, role and Workspace membership.</li>
        <li>A hashed password (we never store or see the password itself).</li>
        <li>Security records: sign-in sessions, IP address and browser user agent in our audit log.</li>
    </ul>

    <h3>Leads and customers managed in a Workspace</h3>
    <ul>
        <li>Contact details such as name, phone number, e-mail address, company, job title and city.</li>
        <li>Notes, activities, follow-up tasks, pipeline stage and ownership that Workspace members record.</li>
        <li>Where a lead came from (for example the ad campaign, ad set, ad and form identifiers and names).</li>
    </ul>

    <h3>Data received from Meta (Facebook, Instagram and WhatsApp)</h3>
    <p>Only when a Workspace administrator connects these services, and only for the purposes in section 3:</p>
    <ul>
        <li>
            <strong>Facebook Login for Business.</strong> The administrator's app-scoped Facebook user ID and name, the
            permissions they granted, the list of Facebook Pages they manage and the access tokens for the Pages they
            choose to connect.
        </li>
        <li>
            <strong>Facebook and Instagram Lead Ads.</strong> The answers a person submits on a lead form of a connected
            Page (Instagram lead ads are delivered through the linked Facebook Page), together with the form, ad and
            campaign identifiers, submission time and whether the lead was organic.
        </li>
        <li>
            <strong>WhatsApp Business Platform.</strong> For a WhatsApp Business number the Workspace connects: the
            WhatsApp ID, phone number and profile name of people who message the business; the content and media of
            messages sent and received; delivery and read statuses; opt-out and opt-in replies; and the business's
            approved message templates.
        </li>
    </ul>

    <h3>Cookies</h3>
    <p>
        We use only strictly necessary cookies: an encrypted session cookie that keeps you signed in and a CSRF cookie
        that protects forms. We do not use advertising or third-party tracking cookies.
    </p>

    <h2>3. How we use data</h2>
    <ul>
        <li>To provide the Service: create and update leads from lead forms, show the shared WhatsApp inbox, send the
            messages Workspace members choose to send, and track follow-ups.</li>
        <li>To keep the Service secure: authentication, access control by role, rate limiting, fraud and abuse
            prevention, and audit logs.</li>
        <li>To support you and to meet legal obligations.</li>
    </ul>
    <p>
        We do not sell personal data, we do not use it for advertising, and we do not use data received from Meta to
        build profiles, for surveillance, for eligibility decisions, or for any purpose other than providing the
        Service to the Workspace that connected it. Messages are sent on WhatsApp only when a Workspace member chooses
        to send them; marketing templates are blocked for contacts who have opted out.
    </p>

    <h2>4. Sharing</h2>
    <ul>
        <li><strong>Within a Workspace.</strong> Data is visible to that Workspace's members according to their role.
            Workspaces are isolated from each other.</li>
        <li><strong>Meta.</strong> When a Workspace member sends a WhatsApp message, its content and the recipient's
            number are sent to Meta to deliver it. Meta's own use is governed by Meta's policies.</li>
        <li><strong>Service providers</strong> that host and operate the Service (hosting, database, e-mail delivery),
            bound by confidentiality and data protection terms.</li>
        <li><strong>Legal reasons</strong>, when required by law or to protect rights and safety.</li>
    </ul>

    <h2>5. Retention</h2>
    <ul>
        <li>Lead, customer and conversation data is kept while the Workspace uses the Service, or until the Workspace
            deletes it or asks us to.</li>
        <li>Technical records of Lead Ads imports hold identifiers and outcomes only and are deleted after
            {{ (int) config('meta.retention_days', 90) }} days.</li>
        <li>When a Facebook or WhatsApp connection is disconnected, its access tokens are deleted immediately; leads
            already imported are kept by the Workspace.</li>
        <li>Account data is deleted or anonymized within {{ $deletionDays }} days after the account is closed, except
            where we must keep records for legal or security reasons.</li>
    </ul>

    <h2>6. Security</h2>
    <p>
        Data is sent over HTTPS. Access tokens and sessions are encrypted at rest. Access is limited by role and
        Workspace. Webhooks from Meta are accepted only with a valid signature. No method of storage or transmission is
        perfectly secure, but we work to protect your data and will notify affected parties of a breach as the law
        requires.
    </p>

    <h2>7. Your rights and choices</h2>
    <p>
        Depending on where you live (for example under India's Digital Personal Data Protection Act, 2023 or the EU
        GDPR), you may have the right to access, correct, delete or obtain a copy of your personal data, to withdraw
        consent and to complain to a data protection authority. To exercise them, write to @include('legal.contact').
        If you are a lead or customer of a business that uses the Service, you can also contact that business directly.
    </p>
    <p>
        You can remove our app's access to your Facebook account at any time in Facebook Settings &gt; Apps and
        Websites, and you can reply STOP to a business on WhatsApp to opt out of its marketing messages. See our
        <a href="{{ route('legal.data-deletion') }}">Data Deletion Instructions</a> for how to have your data deleted.
    </p>

    <h2>8. International transfers</h2>
    <p>
        Data may be processed in countries other than yours, including by Meta when messages are delivered. Where
        required, we use appropriate safeguards for such transfers.
    </p>

    <h2>9. Children</h2>
    <p>The Service is for businesses and is not directed at children under 18.</p>

    <h2>10. Changes</h2>
    <p>We will post any change to this policy on this page and update the date above. Significant changes will be
        announced in the Service.</p>

    <h2>11. Contact</h2>
    <p>
        {{ $company }}@if ($address), {{ $address }}@endif. E-mail: @include('legal.contact').
    </p>
@endsection
