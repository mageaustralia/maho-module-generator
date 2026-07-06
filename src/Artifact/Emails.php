<?php

/**
 * Maho Module Generator
 *
 * @copyright  Copyright (c) 2026 Mage Australia
 * @license    https://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace MahoModuleGenerator\Artifact;

use MahoModuleGenerator\Spec;
use MahoModuleGenerator\Strings;
use MahoModuleGenerator\Tpl;

/**
 * Responsive HTML email template skeleton per declared email:
 * viewport + Apple no-reformat metas, table layout for Outlook, @media
 * stack rule for phones, preheader div, <!--@subject@--> directive.
 * No {{foreach}} - Maho's filter doesn't support it; list content is
 * expected as a pre-rendered *_html var.
 */
final class Emails implements ArtifactGenerator
{
    #[\Override]
    public function generate(Spec $spec, Strings $strings): array
    {
        $out = [];
        $alias = $spec->alias();
        foreach ($spec->emails as $code => $email) {
            $varsComment = json_encode(
                array_combine(
                    (array) $email['vars'],
                    array_map(static fn(string $v): string => ucwords(str_replace('_', ' ', $v)), (array) $email['vars']),
                ) ?: new \stdClass(),
                JSON_UNESCAPED_SLASHES,
            );
            $title = ucwords(str_replace('_', ' ', $code));
            $out["app/locale/en_US/template/email/$alias/$code.html"] = Tpl::render(<<<'TPL'
<!--@subject {{Subject}} @-->
<!--@vars {{VarsComment}}@-->
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="x-apple-disable-message-reformatting">
<meta http-equiv="X-UA-Compatible" content="IE=edge">
<title>{{Title}}</title>
<style type="text/css">
    body, table, td, a { -webkit-text-size-adjust: 100%; -ms-text-size-adjust: 100%; }
    table, td { mso-table-lspace: 0pt; mso-table-rspace: 0pt; }
    body { margin: 0 !important; padding: 0 !important; width: 100% !important; }
    @media only screen and (max-width: 600px) {
        .container { width: 100% !important; max-width: 100% !important; }
        .pad-outer { padding: 20px 14px !important; }
        .pad-inner { padding: 22px 18px !important; }
        .h1 { font-size: 20px !important; line-height: 1.3 !important; }
    }
</style>
<!--[if mso]>
<style type="text/css">body, table, td, div, p { font-family: Arial, Helvetica, sans-serif !important; }</style>
<![endif]-->
</head>
<body style="margin:0; padding:0; background-color:#f3f4f6; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; color:#1f2937;">

<div style="display:none; font-size:1px; color:#f3f4f6; line-height:1px; max-height:0; max-width:0; opacity:0; overflow:hidden; mso-hide:all;">
    {{Title}}
</div>

<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="background-color:#f3f4f6;">
    <tr>
        <td align="center" class="pad-outer" style="padding:32px 12px;">
            <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="600" class="container" style="max-width:600px; width:100%; background-color:#ffffff; border-radius:8px; overflow:hidden;">
                <tr>
                    <td class="pad-inner" style="padding:32px;">
                        <h1 class="h1" style="margin:0 0 12px; font-size:22px; line-height:1.3; font-weight:700; color:#0f172a;">
                            {{Title}}
                        </h1>
                        <p style="margin:0; font-size:15px; line-height:1.55; color:#4b5563;">
                            TODO: write the body copy. Available vars: {{VarsList}}
                        </p>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>

</body>
</html>

TPL, [
                'Subject' => $email['subject'],
                'VarsComment' => $varsComment,
                'Title' => $title,
                'VarsList' => implode(', ', array_map(
                    static fn(string $v): string => '{{var ' . $v . '}}',
                    (array) $email['vars'],
                )) ?: '(none declared)',
            ]);
        }
        return $out;
    }
}
