<?php

namespace Fruitcake\ImageVariants\Twig;

use Fruitcake\ImageVariants\VariantFactory;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Adds variant(), srcset() and variant_size() to Twig:
 *
 *     {{ variant('img/bg.jpg', 'hero') }}
 *     {{ variant('content/team/photo.jpg', 'photo', 'webp', team.key) }}
 *     {{ variant('img/bg.jpg', {cover: [600, 500], quality: 80}, 'webp') }}
 *     {{ srcset('img/bg.jpg', [640, 1024, 1600], 'webp', 'hero') }}
 *
 *     {% set size = variant_size('img/bg.jpg', 'photo') %}
 *     <img src="{{ variant('img/bg.jpg', 'photo') }}"
 *          {% if size %}width="{{ size.width }}" height="{{ size.height }}"{% endif %}>
 */
class VariantExtension extends AbstractExtension
{
    public function __construct(protected VariantFactory $variants)
    {
    }

    /**
     * @return list<TwigFunction>
     */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('variant', $this->variants->url(...)),
            new TwigFunction('srcset', $this->variants->srcset(...)),
            // Not "dimensions": Twig functions share one global namespace with
            // whatever else the application has registered.
            new TwigFunction('variant_size', $this->variants->dimensions(...)),
        ];
    }
}
