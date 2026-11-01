<?php
declare(strict_types=1);

namespace FlxCoreHC;

/**
 * Ownership & White-Label Standard — the one place that holds the ownership facts of this product.
 * Nothing in the application can change these values (no setting, no screen, no tenant). A sale or a rename is
 * a scripted change of this file (see docs/OWNERSHIP_ADOPTION_RECORD.md).
 *
 * The English wording below is the controlling text and is copied exactly from the standard; the Spanish
 * courtesy translation in spanish() is informational and is a draft for attorney review.
 */
final class Ownership
{
    public const PRODUCT      = 'FlxCoreHC';
    public const PRODUCT_LINE = 'Healthcare Compliance Intelligence Platform';
    public const OWNER        = 'Indisse-BTS';
    public const YEARS        = '2026';
    /** Permission that opens the full About & ownership view. */
    public const VIEW_PERMISSION = 'license.view';

    public static function copyright(): string
    {
        return '© ' . self::YEARS . ' ' . self::OWNER . '. All rights reserved.';
    }

    /** First comment of every program file (see tools/ownership_headers.php). */
    public static function fileHeader(): string
    {
        return '© ' . self::YEARS . ' ' . self::OWNER . '. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.';
    }

    public static function poweredBy(): string
    {
        return 'Powered by ' . self::PRODUCT . ' · ' . self::copyright();
    }

    public static function developedBy(): string
    {
        return self::PRODUCT . ' is designed, developed and owned by ' . self::OWNER
            . ', drawing on 30 years managing IT operations for multiple companies in Puerto Rico and Florida.';
    }

    public static function independentDeveloper(): string
    {
        return self::sub('<Product> is an independent product. It was conceived, designed and developed by ' . self::OWNER
            . ' as an independent software developer, and not as an employee, contractor or consultant of any company or organization. '
            . 'It was not commissioned by, and is not a work made for hire for, any organization that uses it. '
            . 'Licensing, subscribing to, using, testing or requesting features of <Product> gives no organization any ownership interest in it, '
            . 'in its updates, or in improvements made at its request.');
    }

    /** The eight lines, in this order. */
    public static function licenseTerms(): array
    {
        return array_map([self::class, 'sub'], [
            '<Product> is proprietary software. It is licensed, not sold, whether it runs on the organization\'s own server or as a hosted service.',
            'Each organization uses <Product> under its own written agreement with the owner: a license agreement for its own installation, or a subscription agreement for the hosted service. That agreement sets the term, the installation or workspace, the number of users and the modules.',
            'A license or subscription does not transfer ownership of the software, its source code, design, database structure, documentation, or any change or improvement made to it.',
            'Unless the agreement allows it in writing: no copying beyond the licensed installation and its backups, no redistribution, sublicensing, resale or hosting for others, no reverse engineering, and no removal of these notices.',
            'The organization owns its own data. The owner of <Product> claims no right to it.',
            'Names of other products, services and agencies (for example the systems <Product> connects to) only identify what <Product> connects to or works with. They belong to their owners and imply no affiliation or endorsement.',
            'Ideas, requests, feedback or testing by an organization do not make it an owner or co-author of <Product> or of any feature made from them. Every update, module and customization belongs to the owner and is licensed under the organization\'s agreement.',
            'The owner is an independent software vendor, not an employee or agent of any organization. Any other work the owner does for an organization is separate from the agreement and transfers no right in <Product>.',
        ]);
    }

    public const TERMS_CLOSING = 'The signed agreement of each organization is what applies.';
    public const OPEN_SOURCE   = 'This software includes open-source components; your administrator can show the notices.';
    public const SIGNIN_STATEMENT = 'Proprietary software, licensed, not sold.';
    public const NO_LICENSE    = 'No license record installed';

    private static function sub(string $s): string
    {
        return str_replace('<Product>', self::PRODUCT, $s);
    }

    /**
     * Spanish courtesy translation of the statements above — INFORMATIONAL; the English text controls.
     * Draft for attorney review.
     */
    public static function spanish(): array
    {
        $p = self::PRODUCT; $o = self::OWNER;
        return [
            'developed' => "$p es diseñado, desarrollado y propiedad de $o, con base en 30 años administrando operaciones de TI para varias empresas en Puerto Rico y Florida.",
            'independent' => "$p es un producto independiente. Fue concebido, diseñado y desarrollado por $o como desarrollador independiente de software, y no como empleado, contratista ni consultor de ninguna empresa u organización. No fue encargado por ninguna organización que lo use ni es una obra por encargo (work made for hire) para ella. Licenciar, suscribirse, usar, probar o solicitar funciones de $p no otorga a ninguna organización ningún derecho de propiedad sobre el producto, sus actualizaciones ni las mejoras hechas a su solicitud.",
            'terms' => [
                "$p es software propietario. Se licencia, no se vende, ya sea que funcione en el servidor de la propia organización o como servicio alojado.",
                "Cada organización usa $p bajo su propio acuerdo escrito con el titular: un acuerdo de licencia para su propia instalación, o un acuerdo de suscripción para el servicio alojado. Ese acuerdo fija el término, la instalación o el espacio de trabajo, el número de usuarios y los módulos.",
                "Una licencia o suscripción no transfiere la propiedad del software, su código fuente, diseño, estructura de base de datos, documentación, ni cambio o mejora alguna hecha al mismo.",
                "Salvo que el acuerdo lo permita por escrito: no se permite copiar más allá de la instalación licenciada y sus copias de seguridad, ni redistribuir, sublicenciar, revender o alojar para terceros, ni aplicar ingeniería inversa, ni eliminar estos avisos.",
                "La organización es dueña de sus propios datos. El titular de $p no reclama ningún derecho sobre ellos.",
                "Los nombres de otros productos, servicios y agencias (por ejemplo, los sistemas con los que $p se conecta) solo identifican aquello con lo que $p se conecta o trabaja. Pertenecen a sus dueños y no implican afiliación ni respaldo.",
                "Las ideas, solicitudes, comentarios o pruebas de una organización no la convierten en titular ni coautora de $p ni de ninguna función creada a partir de ellos. Toda actualización, módulo y personalización pertenece al titular y se licencia bajo el acuerdo de la organización.",
                "El titular es un proveedor independiente de software, no un empleado ni agente de ninguna organización. Cualquier otro trabajo que el titular haga para una organización es independiente del acuerdo y no transfiere ningún derecho sobre $p.",
            ],
            'closing' => 'Lo que se aplica es el acuerdo firmado de cada organización.',
        ];
    }

    /**
     * The `about` read. Short fields for everyone signed in; the full view only for a caller who holds license.view
     * (the server decides — the short answer carries no full-view field).
     *
     * @return array<string,mixed>
     */
    public static function about(TenantContext $ctx): array
    {
        $short = [
            'product'      => self::PRODUCT,
            'product_line' => self::PRODUCT_LINE,
            'developed_by' => self::developedBy(),
            'independent_developer' => self::independentDeveloper(),
            'copyright'    => self::copyright(),
            'version'      => defined('FLXCOREHC_VERSION') ? FLXCOREHC_VERSION : '',
            'licensed_to'  => $ctx->tenantName,
            'open_source'  => self::OPEN_SOURCE,
        ];
        if (!Authz::can($ctx, self::VIEW_PERMISSION)) return ['view' => 'short'] + $short;

        $org  = (new OrgService($ctx))->get();
        $plan = PlanService::forTenant($ctx->tenantId);
        $limit = PlanService::seatLimit($ctx->tenantId);
        $full = [
            'owner'            => self::OWNER,
            'licensed_to'      => $ctx->tenantName,
            'agreement_number' => null,   // the signed license record arrives with the licensing release; until then: "No license record installed"
            'deployment_model' => 'hosted',
            'term'             => null,
            'plan'             => $plan['name'] ?? null,
            'modules'          => array_values(PlanService::featuresFor($ctx->tenantId)),
            'user_limit'       => $limit,
            'users_in_use'     => PlanService::seatCount($ctx->tenantId),
            'name_shown'       => (string) (($org['brand_name'] ?? '') !== '' ? $org['brand_name'] : self::PRODUCT),
            'workspace_id'     => OrgService::workspaceId($ctx->tenantId),
            'license_terms'    => self::licenseTerms(),
            'license_terms_closing' => self::TERMS_CLOSING,
            'license_record'   => self::NO_LICENSE,
        ];
        return ['view' => 'full'] + $short + $full;
    }
}
