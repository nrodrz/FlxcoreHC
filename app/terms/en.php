<?php /* © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt. */
/**
 * Terms of Use — English (the controlling text). Ships with the app; it cannot be edited from a screen.
 * DRAFT for attorney review. Changing the 'version' makes every user accept again at their next sign-in (material change).
 * Placeholders: {organization} = the organization's own name, {product} = the product name.
 */
return [
    'version' => '1.0-draft',
    'title'   => 'Terms of Use',
    'draft'   => 'DRAFT — for attorney review. These Terms are not final.',
    'sections' => [
        ['Who these Terms are between', [
            'These Terms of Use ("Terms") govern the use of {product} by {organization} (the "Organization") and by every person who signs in to the Organization\'s workspace ("you").',
            'They apply to the Organization and its users that have no signed agreement of their own, including free users. Where the Organization has a signed license or subscription agreement, that agreement prevails over these Terms.',
        ]],
        ['What {product} is', [
            '{product} helps an Organization record who holds which licenses and credentials, what is expiring or missing, and keeps evidence and an audit trail.',
            '{product} is a tracking tool. It does not replace the Organization\'s own primary-source verification, credentialing decisions, legal advice or compliance review, and it does not submit applications to payers or agencies on the Organization\'s behalf.',
        ]],
        ['Your account', [
            'Sign in only with your own account and keep your password and second factor private. You are responsible for what is done with your account.',
            'A person who accepts these Terms for the Organization confirms that they have the authority to do so.',
        ]],
        ['The Organization\'s data', [
            'The Organization owns its own data. The owner of {product} claims no right to it.',
            'You must enter only information that the Organization is entitled to hold and use, and you must follow the laws and agreements that apply to it, including those that protect health and personal information.',
        ]],
        ['Acceptable use', [
            'Do not attempt to read another organization\'s data, bypass the product\'s access controls, overload or probe the service, or use it to break the law.',
            'Do not copy, redistribute, sublicense, resell or host {product} for others, reverse engineer it, or remove its notices, unless a written agreement allows it.',
        ]],
        ['Ownership and independent development', [
            '{product} is an independent product. It was conceived, designed and developed by {owner} as an independent software developer, and not as an employee, contractor or consultant of any company or organization. It was not commissioned by, and is not a work made for hire for, any organization that uses it. Licensing, subscribing to, using, testing or requesting features of {product} gives no organization any ownership interest in it, in its updates, or in improvements made at its request.',
            '{product} is licensed, not sold. Use of it does not transfer ownership of the software, its source code, design, database structure, documentation, or any change or improvement made to it.',
        ]],
        ['Other names', [
            'Names of other products, services and agencies only identify what {product} connects to or works with. They belong to their owners and imply no affiliation or endorsement.',
        ]],
        ['Changes to the service', [
            '{product} may be updated, and features may change or be withdrawn. Availability is not guaranteed unless a signed agreement says so.',
        ]],
        ['Disclaimer and limits', [
            'Unless a signed agreement says otherwise, {product} is provided "as is", without warranty of any kind, and the owner is not liable for indirect or consequential loss, or for decisions the Organization takes on the information it records.',
        ]],
        ['Changes to these Terms', [
            'When these Terms change in a material way, they get a new version and every user must accept them again at their next sign-in. For a minor change, a notice is shown, and continued use after 30 days is acceptance.',
            'Every acceptance is recorded with the user, the Organization, the version, and the date and time.',
        ]],
    ],
];
