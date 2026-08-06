<?php

namespace Fruitcake\ImageVariants\Http;

use Fruitcake\ImageVariants\VariantFactory;
use Fruitcake\ImageVariants\VariantGenerator;
use Illuminate\Http\Request;
use Illuminate\Image\ImageException;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Serves an image variant, generating it on the first request.
 *
 * Only that first request reaches here. Afterwards the file exists below the
 * public storage symlink, so the web server answers straight from disk and this
 * controller — and the hash check in it — never runs again. That is why the hash
 * guards generation rather than access.
 */
class VariantController
{
    public function __invoke(
        Request $request,
        VariantFactory $factory,
        VariantGenerator $generator,
        string $preset,
        string $hash,
        string $name,
    ): BinaryFileResponse {
        try {
            $variant = $factory->fromRequest($request, $preset, $name);

            // Nothing is read from or written to disk until the URL proves it came
            // from us; otherwise the endpoint would be an open invitation to fill
            // the cache with junk.
            if (! hash_equals($variant->hash(), $hash)) {
                abort(404);
            }

            $path = $generator->generate($variant);
        } catch (InvalidArgumentException|ImageException $e) {
            // A URL we cannot honour is a bad request, not a server fault.
            abort(404, $e->getMessage());
        }

        return response()->file($path, [
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ]);
    }
}
