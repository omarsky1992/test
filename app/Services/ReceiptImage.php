<?php

namespace App\Services;

use App\Enums\DocumentStatus;
use App\Models\Payment;
use App\Support\Money;
use ArPHP\I18N\Arabic;
use GdImage;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The receipt («سند قبض») drawn as a PNG image or a one-page PDF, to send with the payment message.
 * It shows what the printed receipt shows; it reads the payment and changes nothing.
 */
class ReceiptImage
{
    private const WIDTH = 900;

    private const PAD = 48;

    private ?Arabic $arabic = null;

    public function __construct(private Settings $settings, private PaymentService $payments) {}

    /**
     * What the receipt shows.
     *
     * @return array{company: string, sub: string, number: string, rows: list<array{0: string, 1: string}>, amount: string, words: string, employee: string, voided: bool}
     */
    public function data(Payment $payment): array
    {
        $payment->loadMissing(['subscriber', 'account.branch', 'creator', 'lines.method', 'lines.moneyAccount']);
        $remaining = (int) $payment->account->debts()->whereIn('status', ['open', 'partial'])->sum('balance') - $this->payments->creditBalance($payment->account);
        $methods = $payment->lines->map(fn ($l) => trim($l->method->name_ar.($l->reference ? " ({$l->reference})" : '')))->implode(' + ');

        return [
            'company' => (string) $this->settings->get('company.name'),
            'sub' => collect([$payment->account->branch?->name, $this->settings->get('company.address'), $this->settings->get('company.phone')])->filter()->join(' · '),
            'number' => (string) $payment->receipt_number,
            'rows' => [
                ['التاريخ والوقت', $payment->received_at->setTimezone(config('app.timezone'))->format('Y/m/d H:i')],
                ['استلمنا من', (string) $payment->subscriber?->full_name],
                ['الهاتف', (string) $payment->subscriber?->phone],
                ['الحساب', (string) $payment->account->username],
                ['طريقة الدفع', $methods ?: '—'],
                ['الرصيد المتبقي على الحساب', Money::format($remaining)],
            ],
            'amount' => Money::format($payment->amount),
            'words' => Money::inWords($payment->amount).' لا غير',
            'employee' => (string) $payment->creator?->name,
            'voided' => $payment->status === DocumentStatus::Voided,
        ];
    }

    /** A made-up receipt for «اختبار رسائل الواتساب». */
    public function sample(string $name, string $device, int $amount): array
    {
        return [
            'company' => (string) $this->settings->get('company.name'),
            'sub' => (string) $this->settings->get('company.phone'),
            'number' => 'TEST-0001',
            'rows' => [
                ['التاريخ والوقت', now()->setTimezone(config('app.timezone'))->format('Y/m/d H:i')],
                ['استلمنا من', $name],
                ['الهاتف', '07700000000'],
                ['الحساب', $device],
                ['طريقة الدفع', 'نقداً'],
                ['الرصيد المتبقي على الحساب', Money::format(0)],
            ],
            'amount' => Money::format($amount),
            'words' => Money::inWords($amount).' لا غير',
            'employee' => (string) auth()->user()?->name,
            'voided' => false,
        ];
    }

    /** Draws it and keeps it on the private disk; returns the outbox media fields. */
    public function store(array $data, string $format, string $folder): array
    {
        $pdf = $format === 'pdf';
        $path = trim($folder, '/').'/'.Str::uuid().'/وصل-'.preg_replace('/[^\w\-]+/u', '-', $data['number']).($pdf ? '.pdf' : '.png');
        Storage::disk('local')->put($path, $pdf ? $this->pdf($data) : $this->png($data));

        return ['media_path' => $path, 'media_mime' => $pdf ? 'application/pdf' : 'image/png'];
    }

    public function png(array $data): string
    {
        return $this->encode($this->draw($data), 'png');
    }

    /** One page holding the drawn receipt (A5 width). */
    public function pdf(array $data): string
    {
        $image = $this->draw($data);
        [$w, $h] = [imagesx($image), imagesy($image)];
        $jpeg = $this->encode($image, 'jpeg');
        [$pw, $ph] = [420, round(420 * $h / $w, 2)];
        $content = "q {$pw} 0 0 {$ph} 0 0 cm /Im0 Do Q";
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 {$pw} {$ph}] /Resources << /XObject << /Im0 4 0 R >> >> /Contents 5 0 R >>",
            "<< /Type /XObject /Subtype /Image /Width {$w} /Height {$h} /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length ".strlen($jpeg)." >>\nstream\n{$jpeg}\nendstream",
            '<< /Length '.strlen($content)." >>\nstream\n{$content}\nendstream",
        ];
        $out = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $i => $object) {
            $offsets[] = strlen($out);
            $out .= ($i + 1)." 0 obj\n{$object}\nendobj\n";
        }
        $xref = strlen($out);
        $out .= "xref\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $out .= sprintf("%010d 00000 n \n", $offset);
        }

        return $out.'trailer << /Size '.(count($objects) + 1)." /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF\n";
    }

    private function draw(array $data): GdImage
    {
        $rows = count($data['rows']);
        $height = 210 + $rows * 58 + 300;
        $img = imagecreatetruecolor(self::WIDTH, $height);
        $white = imagecolorallocate($img, 255, 255, 255);
        $ink = imagecolorallocate($img, 28, 27, 25);
        $muted = imagecolorallocate($img, 94, 91, 83);
        $line = imagecolorallocate($img, 214, 209, 196);
        $accent = imagecolorallocate($img, 15, 118, 110);
        $soft = imagecolorallocate($img, 238, 246, 246);
        imagefilledrectangle($img, 0, 0, self::WIDTH, $height, $white);
        [$right, $left] = [self::WIDTH - self::PAD, self::PAD];

        // Header: the agent on the right, «سند قبض» and its number on the left.
        $this->text($img, $data['company'], 30, $right, 84, $ink, true);
        $this->text($img, $data['sub'], 17, $right, 122, $muted);
        $this->text($img, 'سند قبض', 32, $left, 84, $ink, true, 'left');
        $this->text($img, $data['number'], 19, $left, 122, $ink, true, 'left');
        imagefilledrectangle($img, $left, 146, $right, 149, $ink);

        $y = 210;
        foreach ($data['rows'] as [$label, $value]) {
            $this->text($img, $label, 19, $right, $y, $muted);
            $this->text($img, $value, 19, $left, $y, $ink, true, 'left');
            for ($x = $left; $x < $right; $x += 14) {
                imageline($img, $x, $y + 20, min($x + 7, $right), $y + 20, $line);
            }
            $y += 58;
        }

        // The amount, in figures and in words.
        $box = $y;
        imagefilledrectangle($img, $left, $box, $right, $box + 170, $soft);
        imagesetthickness($img, 3);
        imagerectangle($img, $left, $box, $right, $box + 170, $accent);
        imagesetthickness($img, 1);
        $this->text($img, 'المبلغ', 17, $right - 24, $box + 40, $muted);
        $this->text($img, $data['amount'], 40, $right - 24, $box + 100, $accent, true);
        $this->text($img, $data['words'], 18, $right - 24, $box + 146, $ink);

        $y = $box + 240;
        $this->text($img, 'الموظف: '.$data['employee'], 19, $right, $y, $ink, true);
        $this->text($img, 'التوقيع', 17, $left + 80, $y, $muted, false, 'left');
        imageline($img, $left, $y - 34, $left + 240, $y - 34, $ink);

        if ($data['voided']) {
            $red = imagecolorallocatealpha($img, 185, 28, 28, 90);
            imagettftext($img, 110, 18, (int) (self::WIDTH * 0.28), (int) ($height * 0.62), $red, $this->font(true), $this->shape('ملغى'));
        }

        return $img;
    }

    /** Arabic text joined and ordered for drawing, aligned on its right (or left) edge at $x. */
    private function text(GdImage $img, string $text, int $size, int $x, int $y, int $color, bool $bold = false, string $align = 'right'): void
    {
        if (trim($text) === '') {
            return;
        }
        $shaped = $this->shape($text);
        $font = $this->font($bold);
        $box = imagettfbbox($size, 0, $font, $shaped);
        $width = abs($box[2] - $box[0]);
        imagettftext($img, $size, 0, $align === 'right' ? $x - $width : $x, $y, $color, $font, $shaped);
    }

    /**
     * Visual order for a right-to-left line: runs of Latin letters and digits (with the brackets and
     * marks around them) stay as they are, the Arabic between them is joined, and the runs are laid
     * right to left.
     */
    private function shape(string $text): string
    {
        if (! preg_match('/\p{Arabic}/u', $text)) {
            return $text;
        }
        $this->arabic ??= new Arabic;
        $runs = preg_split('/([(\[]?[A-Za-z0-9][A-Za-z0-9_.,:\/\-+@#% ]*[A-Za-z0-9][)\]]?|[(\[]?[A-Za-z0-9][)\]]?| *· *)/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        $visual = array_map(fn (string $run) => preg_match('/\p{Arabic}/u', $run)
            // The spaces around a run keep their place (laid right to left: the leading one on the right).
            ? str_repeat(' ', strlen($run) - strlen(rtrim($run))).$this->arabic->utf8Glyphs(trim($run), 500, false).str_repeat(' ', strlen($run) - strlen(ltrim($run)))
            : (preg_match('/[A-Za-z0-9]/', $run) ? $run : implode('', array_reverse(mb_str_split($run)))), $runs);

        return implode('', array_reverse($visual));
    }

    private function font(bool $bold): string
    {
        return resource_path('fonts/'.($bold ? 'DejaVuSans-Bold.ttf' : 'DejaVuSans.ttf'));
    }

    private function encode(GdImage $image, string $type): string
    {
        ob_start();
        $type === 'png' ? imagepng($image, null, 6) : imagejpeg($image, null, 90);

        return (string) ob_get_clean();
    }
}
