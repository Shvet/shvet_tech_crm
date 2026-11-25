@extends('legal.layout')

@section('title', 'Terms of Service')
@section('description', 'The terms for using '.$appName.', operated by '.$company.'.')

@section('content')
    <p>
        These terms govern use of {{ $appName }} (the "Service"), operated by {{ $company }} ("we", "us"). By signing in
        to or using the Service you agree to them on behalf of yourself and the business that gave you access (the
        "Workspace"). Our <a href="{{ route('legal.privacy') }}">Privacy Policy</a> explains how we handle data.
    </p>

    <h2>1. Accounts</h2>
    <ul>
        <li>Accounts are created by a Workspace administrator; there is no public sign-up.</li>
        <li>Keep your credentials confidential and tell us promptly about any unauthorized use.</li>
        <li>Workspace administrators are responsible for who they invite and which role each member has.</li>
    </ul>

    <h2>2. Acceptable use</h2>
    <p>You agree not to:</p>
    <ul>
        <li>break any law, or infringe anyone's privacy or other rights;</li>
        <li>send spam or unsolicited messages, or message people on WhatsApp who have not opted in to hear from the
            business;</li>
        <li>upload malware, probe or disrupt the Service, or access another Workspace's data;</li>
        <li>resell or provide the Service to third parties without our written permission.</li>
    </ul>

    <h2>3. Facebook, Instagram and WhatsApp</h2>
    <ul>
        <li>Connecting Meta products is optional. When you connect them you also agree to Meta's terms, including the
            Meta Platform Terms, Meta's Commercial Terms, the Lead Ads Terms and the WhatsApp Business Messaging and
            Commerce Policies.</li>
        <li>You must have the right to connect the Facebook Pages and WhatsApp Business numbers you connect, and you
            are responsible for obtaining the consents required to contact the people whose data you manage, including
            WhatsApp opt-in before sending business-initiated messages.</li>
        <li>Meta may change or withdraw its APIs at any time; features that depend on them may change as a result.</li>
    </ul>

    <h2>4. Your data</h2>
    <p>
        The Workspace owns the lead, customer and conversation data it puts in or connects to the Service. You give us
        permission to host, process and transmit that data only to provide and secure the Service. You can export or
        delete it, or ask us to, as described in the Privacy Policy.
    </p>

    <h2>5. Availability and changes</h2>
    <p>
        We work to keep the Service available and secure, but it is provided "as is" and "as available". We may change
        features, and will give reasonable notice of changes that materially reduce functionality.
    </p>

    <h2>6. Suspension and termination</h2>
    <p>
        You may stop using the Service at any time. We may suspend or end access for a breach of these terms, to protect
        the Service or others, or when required by law or by Meta. After termination we delete Workspace data as set
        out in the Privacy Policy.
    </p>

    <h2>7. Liability</h2>
    <p>
        To the extent permitted by law, we are not liable for indirect, incidental or consequential losses, lost
        profits or lost data, and our total liability for any claim is limited to the amount paid for the Service in the
        12 months before the claim. Nothing in these terms limits liability that cannot be limited by law.
    </p>

    <h2>8. Governing law</h2>
    <p>These terms are governed by the laws of {{ $governingLaw }}, and its courts have jurisdiction over disputes.</p>

    <h2>9. Changes to these terms</h2>
    <p>We will post updated terms on this page and change the date above. Continued use after a change means you
        accept it.</p>

    <h2>10. Contact</h2>
    <p>{{ $company }}@if ($address), {{ $address }}@endif. E-mail: @include('legal.contact').</p>
@endsection
