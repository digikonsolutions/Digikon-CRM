<?php

namespace App\Services;

use App\Models\Proposal;
use App\Models\Setting;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\Storage;

class ProposalPdf
{
    /** Branding used by every PDF template (Settings > Proposal Branding). */
    public static function branding(): array
    {
        return [
            'company_name' => Setting::get('company_name', config('app.name')),
            'company_email' => Setting::get('company_email'),
            'company_phone' => Setting::get('company_phone'),
            'company_address' => Setting::get('company_address'),
            'company_website' => Setting::get('company_website'),
            'primary_color' => Setting::get('primary_color', '#03171D'),
            'accent_color' => Setting::get('accent_color', '#81D870'),
            'signer_name' => Setting::get('signer_name'),
            'signer_title' => Setting::get('signer_title'),
            'footer_text' => Setting::get('proposal_footer', 'Thank you for considering us.'),
            'logo' => self::imageData(Setting::get('logo_path')),
            'signature' => self::imageData(Setting::get('signature_path')),
        ];
    }

    /** Embed uploaded images as base64 so Dompdf never needs remote access. */
    protected static function imageData(?string $path): ?string
    {
        if (! $path || ! Storage::disk('public')->exists($path)) {
            return null;
        }

        $mime = Storage::disk('public')->mimeType($path) ?: 'image/png';

        return 'data:'.$mime.';base64,'.base64_encode(Storage::disk('public')->get($path));
    }

    public function render(Proposal $proposal): string
    {
        $template = array_key_exists($proposal->template, Proposal::TEMPLATES) ? $proposal->template : 'modern';

        $html = view('pdf.proposals.'.$template, [
            'proposal' => $proposal,
            'brand' => self::branding(),
        ])->render();

        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $options->set('isHtml5ParserEnabled', true);
        $options->set('defaultFont', 'DejaVu Sans');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4');
        $dompdf->render();

        return $dompdf->output();
    }

    public function filename(Proposal $proposal): string
    {
        return $proposal->number.'.pdf';
    }
}
