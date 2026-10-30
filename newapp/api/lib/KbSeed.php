<?php
// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
/**
 * OpsHub — starter Knowledge Base content.
 *
 * Original, plain-language articles seeded as DRAFTS so an admin can review, tailor
 * (replace the [bracketed] placeholders with your real links/contacts) and publish.
 * Idempotent: articles are matched by title and skipped if one already exists.
 */

if (!function_exists('opsh_kb_seed_articles')) {

    function opsh_kb_seed_articles(): array {
        $foot = '<hr><p style="color:#667;font-size:13px"><em>Draft — review and replace the [bracketed] placeholders (support email, portal links, tool names) with your organization’s details before publishing. Owner: [name] · Last reviewed: [date].</em></p>';

        $a = [];

        // ---- House template ----
        $a[] = ['title' => 'How to write a Knowledge Base article (house template)', 'cat' => null, 'content' => <<<'HTML'
<p><strong>TL;DR:</strong> Every article follows the same shape so they’re easy to scan and trust. Copy this structure for new articles.</p>
<h3>Structure</h3>
<ol>
<li><strong>Title as a task or question</strong> — e.g. “How do I set up MFA on my phone?”, not “MFA”.</li>
<li><strong>TL;DR</strong> — one or two sentences with the answer up top.</li>
<li><strong>Who this is for / prerequisites</strong> — who needs it and what they need first.</li>
<li><strong>Steps</strong> — numbered, one action per step, with screenshots where helpful.</li>
<li><strong>Troubleshooting / FAQ</strong> — the common ways it goes wrong.</li>
<li><strong>Related articles</strong> and a footer with the <strong>owner</strong> and <strong>last reviewed</strong> date.</li>
</ol>
<h3>Writing style</h3>
<ul>
<li>Plain language, short sentences, one task per article.</li>
<li>Write for the reader (“you”), not about the system.</li>
<li>Say what to click, in order. Avoid jargon; spell out acronyms once.</li>
</ul>
HTML];

        // ---- Microsoft 365 ----
        $a[] = ['title' => 'Signing in to Microsoft 365', 'cat' => 'microsoft-365', 'content' => <<<'HTML'
<p><strong>TL;DR:</strong> Go to <a href="https://www.office.com">office.com</a>, choose <em>Sign in</em>, and use your work email and password. First time in, you’ll be asked to set up extra security (MFA).</p>
<h3>Who this is for</h3>
<p>Anyone with a work Microsoft 365 account (email, Teams, Word, Excel, OneDrive).</p>
<h3>Steps</h3>
<ol>
<li>Open a browser and go to <strong>office.com</strong>.</li>
<li>Click <strong>Sign in</strong>.</li>
<li>Enter your full work email (e.g. <em>first.last@[yourdomain]</em>) and click <strong>Next</strong>.</li>
<li>Enter your password. If it’s your first sign-in, you’ll be prompted to change a temporary password and set up multi-factor authentication.</li>
<li>Once signed in, you’ll see the Microsoft 365 apps. Bookmark this page.</li>
</ol>
<h3>Troubleshooting</h3>
<ul>
<li><strong>Forgot your password?</strong> See “Reset your password yourself”.</li>
<li><strong>“Account locked” or repeated failures?</strong> Contact IT at [itsupport@example.com].</li>
</ul>
HTML];

        $a[] = ['title' => 'Set up multi-factor authentication (MFA)', 'cat' => 'microsoft-365', 'content' => <<<'HTML'
<p><strong>TL;DR:</strong> MFA adds a second check (usually a tap on your phone) when you sign in. Install the <strong>Microsoft Authenticator</strong> app and register it once.</p>
<h3>What you need</h3>
<p>Your work account and a smartphone.</p>
<h3>Steps</h3>
<ol>
<li>On your phone, install <strong>Microsoft Authenticator</strong> from the App Store or Google Play.</li>
<li>On a computer, go to <a href="https://aka.ms/mfasetup">aka.ms/mfasetup</a> and sign in.</li>
<li>Choose <strong>Add sign-in method → Authenticator app</strong> and follow the prompts.</li>
<li>In the app, tap <strong>+</strong> → <strong>Work or school account</strong> → <strong>Scan a QR code</strong>, and scan the code on screen.</li>
<li>Approve the test notification. You’re done.</li>
</ol>
<h3>Everyday use</h3>
<p>When you sign in on a new device, you’ll get a notification — approve it (and enter the number shown, if asked). Never approve a request you didn’t start; that can be an attacker trying your password.</p>
HTML];

        $a[] = ['title' => 'Reset your password yourself', 'cat' => 'microsoft-365', 'content' => <<<'HTML'
<p><strong>TL;DR:</strong> If self-service reset is enabled, go to <a href="https://aka.ms/sspr">aka.ms/sspr</a>, verify your identity with your phone or the Authenticator app, and set a new password — no ticket needed.</p>
<h3>Steps</h3>
<ol>
<li>Go to <strong>aka.ms/sspr</strong> (or click <em>Forgot my password</em> on the sign-in screen).</li>
<li>Enter your work email and the characters shown.</li>
<li>Choose a verification method you registered (text, call, or Authenticator).</li>
<li>Enter the code, then set a new password.</li>
</ol>
<h3>Good to know</h3>
<ul>
<li>Your new password can’t repeat recent ones and must meet length/complexity rules.</li>
<li>Update the password on your phone’s mail app too, or it may lock your account with repeated failures.</li>
<li>If self-service isn’t set up for you, contact IT at [itsupport@example.com].</li>
</ul>
HTML];

        $a[] = ['title' => 'Outlook basics: email, calendar, and signature', 'cat' => 'microsoft-365', 'content' => <<<'HTML'
<p><strong>TL;DR:</strong> Outlook is your email and calendar. Set a signature once, and use the calendar to book meetings and see when people are free.</p>
<h3>Set your signature</h3>
<ol>
<li>In Outlook, open <strong>Settings</strong> (gear icon) → search <strong>Signature</strong>.</li>
<li>Create a signature with your name, title, and [organization]. Set it to apply to new messages and replies.</li>
</ol>
<h3>Calendar</h3>
<ul>
<li>Click <strong>New event</strong>, add people in <em>Invite attendees</em>, and use <strong>Scheduling Assistant</strong> to find a free time.</li>
<li>Add a Teams link with the <strong>Teams meeting</strong> toggle.</li>
</ul>
<h3>Tips</h3>
<ul>
<li>Use <strong>Focused</strong> vs <strong>Other</strong> inbox to cut noise.</li>
<li>Right-click a message to create a rule (e.g. file newsletters automatically).</li>
</ul>
HTML];

        $a[] = ['title' => 'Microsoft Teams: meetings and chat', 'cat' => 'microsoft-365', 'content' => <<<'HTML'
<p><strong>TL;DR:</strong> Teams is chat, calls, and meetings. Use chat for quick questions and channels for team-wide topics.</p>
<h3>Join a meeting</h3>
<ol>
<li>Open the calendar invite and click <strong>Join</strong> (in Teams or from Outlook).</li>
<li>Check your camera and microphone on the pre-join screen, then <strong>Join now</strong>.</li>
</ol>
<h3>Chat vs channels</h3>
<ul>
<li><strong>Chat</strong> — private 1:1 or small group messages.</li>
<li><strong>Channels</strong> — shared conversations for a team or project; anyone in the team can see them.</li>
</ul>
<h3>Etiquette</h3>
<ul>
<li>Mute when you’re not speaking.</li>
<li>Keep client names and personal data out of public channels — use secure locations for that.</li>
</ul>
HTML];

        $a[] = ['title' => 'Where to save your files: OneDrive vs SharePoint', 'cat' => 'microsoft-365', 'content' => <<<'HTML'
<p><strong>TL;DR:</strong> Save personal work files in <strong>OneDrive</strong>; save shared/team files in <strong>SharePoint</strong> or a Teams channel’s <em>Files</em> tab. Avoid saving important work only on your computer’s desktop.</p>
<h3>Why it matters</h3>
<p>Files in OneDrive/SharePoint are backed up, versioned, and reachable from any device. Files only on your laptop can be lost if the device fails.</p>
<h3>Rules of thumb</h3>
<ul>
<li><strong>Just me:</strong> OneDrive.</li>
<li><strong>My team:</strong> the team’s SharePoint site or Teams <em>Files</em>.</li>
<li><strong>Client or sensitive data:</strong> only in the approved location for that data — never a personal email or personal cloud drive.</li>
</ul>
<h3>Sharing</h3>
<p>Use <strong>Share</strong> and give access to specific people. Double-check before choosing “anyone with the link”.</p>
HTML];

        $a[] = ['title' => 'Set up work email on your phone', 'cat' => 'microsoft-365', 'content' => <<<'HTML'
<p><strong>TL;DR:</strong> Install the <strong>Outlook</strong> and <strong>Teams</strong> apps on your phone and sign in with your work account. Use the official apps, not your phone’s built-in mail, so work data stays managed.</p>
<h3>Steps</h3>
<ol>
<li>Install <strong>Microsoft Outlook</strong> (and <strong>Teams</strong>) from your app store.</li>
<li>Open Outlook → <strong>Add account</strong> → enter your work email.</li>
<li>Sign in and approve the MFA prompt.</li>
<li>If asked to register the device or set a PIN, follow the prompts — this protects work data if your phone is lost.</li>
</ol>
<h3>If your phone is lost or stolen</h3>
<p>Tell IT immediately at [itsupport@example.com] so access can be revoked.</p>
HTML];

        // ---- Security awareness ----
        $a[] = ['title' => 'How to spot and report a phishing email', 'cat' => 'security', 'content' => <<<'HTML'
<p><strong>TL;DR:</strong> Phishing tries to trick you into clicking a bad link, opening an attachment, or handing over your password. When in doubt, don’t click — report it.</p>
<h3>Warning signs</h3>
<ul>
<li>Urgency or threats (“your account will be closed”).</li>
<li>Unexpected attachments or links; a sender address that doesn’t match the real organization.</li>
<li>Requests for your password, MFA code, or to buy gift cards.</li>
<li>Slightly-off spelling of names or domains.</li>
</ul>
<h3>What to do</h3>
<ol>
<li>Don’t click links or open attachments.</li>
<li>Don’t reply or forward it to colleagues.</li>
<li>Use the <strong>Report</strong> button in Outlook (Report → Phishing), or forward it to [security@example.com].</li>
<li>If you already clicked or entered your password, change your password now and tell IT.</li>
</ol>
<p>Reporting a false alarm is always better than ignoring a real one.</p>
HTML];

        $a[] = ['title' => 'Strong passwords and using a password manager', 'cat' => 'security', 'content' => <<<'HTML'
<p><strong>TL;DR:</strong> Use long, unique passphrases for every account, and let a password manager remember them. Length beats complexity.</p>
<h3>Make strong passwords</h3>
<ul>
<li>Use a <strong>passphrase</strong> — four or more random words, 14+ characters.</li>
<li>Use a <strong>different</strong> password for every service, so one breach doesn’t open the rest.</li>
<li>Never reuse your work password on personal sites.</li>
</ul>
<h3>Use a password manager</h3>
<p>A password manager creates and stores unique passwords so you only remember one master password. If [organization] provides one, use it; ask IT at [itsupport@example.com].</p>
<h3>Never</h3>
<ul>
<li>Write passwords on sticky notes or in a plain document.</li>
<li>Share your password — IT will never ask for it.</li>
</ul>
HTML];

        $a[] = ['title' => 'Why multi-factor authentication matters', 'cat' => 'security', 'content' => <<<'HTML'
<p><strong>TL;DR:</strong> MFA stops most account takeovers even if someone steals your password, because they still can’t pass the second check.</p>
<h3>The idea</h3>
<p>Signing in needs something you know (password) plus something you have (a tap or code on your phone). An attacker with only your password is stopped at the second step.</p>
<h3>Your part</h3>
<ul>
<li>Set up MFA (see “Set up multi-factor authentication”).</li>
<li><strong>Only approve prompts you started.</strong> If you get a prompt out of nowhere, deny it and change your password — someone may have it.</li>
<li>Don’t share codes with anyone, including people claiming to be IT.</li>
</ul>
HTML];

        $a[] = ['title' => 'Handling client and personal information (PII) safely', 'cat' => 'security', 'content' => <<<'HTML'
<p><strong>TL;DR:</strong> Client and personal data (names, contact details, case notes, health or financial info) must stay in approved systems, be shared only with people who need it, and never leave in personal email or drives.</p>
<h3>Do</h3>
<ul>
<li>Keep client records in the approved system ([system name]) — not spreadsheets on your desktop.</li>
<li>Share on a need-to-know basis; use secure, access-controlled locations.</li>
<li>Lock your screen when you step away.</li>
<li>Report any suspected data exposure immediately to [security@example.com].</li>
</ul>
<h3>Don’t</h3>
<ul>
<li>Email client data to personal accounts or copy it to personal cloud storage or USB drives.</li>
<li>Discuss client details in public Teams channels or where others can overhear.</li>
<li>Leave printouts with personal data unattended — shred what you don’t need.</li>
</ul>
<p>Protecting this information is both a security duty and part of our accreditation obligations.</p>
HTML];

        $a[] = ['title' => 'Sharing files safely: inside vs outside the organization', 'cat' => 'security', 'content' => <<<'HTML'
<p><strong>TL;DR:</strong> Share to <strong>specific people</strong> whenever possible. Be very careful with “anyone with the link”, and never share sensitive data outside without approval.</p>
<h3>Inside the organization</h3>
<ul>
<li>Use <strong>Share</strong> in OneDrive/SharePoint and pick named people or the right team.</li>
<li>Give the least access needed — <em>view</em> unless they must edit.</li>
</ul>
<h3>Outside the organization</h3>
<ul>
<li>Prefer a secure, expiring link limited to the recipient’s email.</li>
<li>Double-check the address — one wrong letter sends data to a stranger.</li>
<li>Sensitive or client data leaving [organization] needs approval per policy.</li>
</ul>
<h3>Check your shares</h3>
<p>In OneDrive, <strong>Shared → Shared by you</strong> lets you review and remove access you no longer need.</p>
HTML];

        $a[] = ['title' => 'Lock your screen and keep your device secure', 'cat' => 'security', 'content' => <<<'HTML'
<p><strong>TL;DR:</strong> Lock your screen every time you step away, keep your device updated, and report loss immediately.</p>
<h3>Everyday habits</h3>
<ul>
<li><strong>Lock it:</strong> Windows key + L (Windows) or Control + Command + Q (Mac).</li>
<li>Set your screen to lock automatically after a few minutes.</li>
<li>Install updates promptly — they fix security holes.</li>
<li>Don’t install unapproved software; ask IT if you need a tool.</li>
</ul>
<h3>Out of the office</h3>
<ul>
<li>Don’t leave devices visible in cars or unattended in public.</li>
<li>Avoid entering sensitive data on public/shared computers.</li>
<li>Lost or stolen device? Tell IT at [itsupport@example.com] right away so access can be cut.</li>
</ul>
HTML];

        $a[] = ['title' => 'Acceptable use of company technology and accounts', 'cat' => 'security', 'content' => <<<'HTML'
<p><strong>TL;DR:</strong> Company accounts, devices, and internet are provided for work. Use them responsibly, protect data, and follow the law and our policies.</p>
<h3>Expected</h3>
<ul>
<li>Use your account only for your own work; keep credentials private.</li>
<li>Protect client and personal data (see “Handling client and personal information safely”).</li>
<li>Report security concerns to [security@example.com].</li>
</ul>
<h3>Not allowed</h3>
<ul>
<li>Sharing accounts or passwords.</li>
<li>Installing unapproved or pirated software.</li>
<li>Using company systems to harass others or to store/share inappropriate or illegal content.</li>
<li>Moving company or client data to personal accounts or devices.</li>
</ul>
<p>This is a plain-language summary — the full Acceptable Use Policy at [policy link] governs. Staff may be asked to acknowledge it during onboarding.</p>
HTML];

        // append the footer note to each
        foreach ($a as &$row) { $row['content'] .= $foot; }
        unset($row);
        return $a;
    }

    /** Insert starter articles as drafts, skipping any whose title already exists. */
    function opsh_kb_seed(PDO $db, $userId): array {
        $arts = opsh_kb_seed_articles();
        // resolve category ids by slug once
        $cats = [];
        try { $cats = $db->query("SELECT slug, id FROM opsh_categories WHERE kind='kb'")->fetchAll(PDO::FETCH_KEY_PAIR); } catch (\Throwable $e) {}
        $created = 0; $skipped = 0;
        $chk = $db->prepare("SELECT id FROM opsh_kb_articles WHERE title=? LIMIT 1");
        $ins = $db->prepare("INSERT INTO opsh_kb_articles (title,slug,content,category_id,author_id,status,created_at) VALUES (?,?,?,?,?, 'draft', NOW())");
        foreach ($arts as $art) {
            $chk->execute([$art['title']]);
            if ($chk->fetch()) { $skipped++; continue; }
            $slug = preg_replace('/[^a-z0-9]+/', '-', strtolower(trim($art['title'])));
            $catId = ($art['cat'] && isset($cats[$art['cat']])) ? (int)$cats[$art['cat']] : null;
            try { $ins->execute([$art['title'], $slug, $art['content'], $catId, (int)$userId]); $created++; }
            catch (\Throwable $e) { $skipped++; }
        }
        return ['created' => $created, 'skipped' => $skipped, 'total' => count($arts)];
    }
}
