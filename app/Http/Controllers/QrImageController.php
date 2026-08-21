<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\QrFormat;
use App\Models\QrCode;
use App\Services\QrRenderer;
use App\Services\RenderSpec;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * The on-screen picture of a code: the list thumbnail and, from M2-T3, the builder
 * preview.
 *
 * Deliberately NOT the export endpoint (M2-T4). It is PNG only and capped small,
 * because the vector file and the print-resolution raster are what a paid plan buys
 * — an uncapped SVG here would hand that away through the back door while the
 * export route was still politely checking entitlements. Screen pixels are free;
 * something a print shop can use is not.
 */
final class QrImageController extends Controller
{
    /**
     * Enough for a retina thumbnail and a builder preview, useless for print.
     */
    private const MAX_PREVIEW_SIZE = 512;

    /**
     * Bumped whenever a renderer default changes shape — quiet zone, eye mapping,
     * logo ratio, the encoded host. Without it the ETag digests only row data, so a
     * deploy that changes how codes are drawn leaves every browser revalidating its
     * old bitmap, getting a 304, and showing the pre-deploy image indefinitely.
     */
    private const RENDERER_VERSION = 1;

    private const CACHE_TTL_SECONDS = 3600;

    public function __invoke(Request $request, QrCode $qrCode, QrRenderer $renderer): Response
    {
        Gate::authorize('view', $qrCode);

        $size = min(
            self::MAX_PREVIEW_SIZE,
            RenderSpec::clampSize((int) $request->integer('size', 320)),
        );

        /*
         * Everything the picture depends on. `updated_at` covers a destination edit
         * that did not touch style — the image is identical, but an owner who just
         * saved expects to see that their save took, and a stale 304 reads as a
         * failure even when the pixels are right.
         */
        $fingerprint = implode('|', [
            self::RENDERER_VERSION,
            $qrCode->slug,
            (string) $qrCode->updated_at?->getTimestamp(),
            (string) json_encode($qrCode->style),
            (string) $size,
            (string) config('app.url'),
        ]);

        $response = new Response;
        $response->setEtag(md5($fingerprint));
        // Private: a code's picture is not a shared asset, and a proxy holding it
        // would serve one owner's code to whoever asked next. No max-age, because an
        // owner who just saved a new colour must not be shown the old one — the
        // render cache below is what makes revalidation cheap.
        $response->headers->set('Cache-Control', 'private, no-cache, must-revalidate');

        // Symfony's comparison, not `===`: RFC 7232 requires WEAK comparison for GET
        // and allows `*` and comma-separated lists, so a hand-rolled equality check
        // silently never matches a client or CDN that sends `W/"..."` — and the one
        // defence against re-rendering disappears while the test still passes.
        if ($response->isNotModified($request)) {
            return $response;
        }

        $png = Cache::remember(
            'qr-preview:'.md5($fingerprint),
            self::CACHE_TTL_SECONDS,
            fn (): string => $this->render($renderer, $qrCode, $size),
        );

        $response->setContent($png);
        $response->headers->set('Content-Type', QrFormat::Png->mimeType());
        $response->headers->set('Content-Disposition', 'inline');

        return $response;
    }

    /**
     * A stored style can be well-formed and still fail the contrast gate — nothing
     * rejects colours on write until M2-T3's builder does. Letting that throw would
     * turn one bad saved colour into a 500 on every card in the owner's list, and
     * into a logged exception every time they load the page.
     *
     * So the backstop draws the code in default ink instead. The owner sees a
     * scannable code that is not the colour they chose, which is recoverable and
     * self-evident; a broken image on every row is neither.
     */
    private function render(QrRenderer $renderer, QrCode $qrCode, int $size): string
    {
        $spec = RenderSpec::fromStyle($qrCode->style ?? [], QrFormat::Png, $size);

        try {
            return $renderer->render($qrCode, $spec);
        } catch (InvalidArgumentException $e) {
            Log::warning('Stored QR style could not be rendered; falling back to defaults.', [
                'qr_code_id' => $qrCode->id,
                'reason' => $e->getMessage(),
            ]);

            return $renderer->render($qrCode, new RenderSpec(format: QrFormat::Png, size: $size));
        }
    }
}
