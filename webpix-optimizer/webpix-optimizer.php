<?php
/**
 * Plugin Name: WebPix Optimizer
 * Plugin URI: https://webpix.io/integrations/wordpress
 * Description: Routes WordPress images, SVG, CSS, JavaScript and font loading through WebPix CDN optimization controls.
 * Version: 1.0.7
 * Author: WebPix
 * Author URI: https://webpix.io
 * License: Proprietary
 * Text Domain: webpix-optimizer
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class Webpix_Optimizer_Plugin
{
    private const OPTION_NAME = 'webpix_optimizer_options';
    private const DEFAULT_CDN_HOST = 'https://cdn.webpix.io';
    private const SUPPORTED_IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'bmp', 'ico', 'svg'];
    private const SUPPORTED_IMAGE_FORMATS = ['webp', 'avif', 'jpeg'];
    private const SUPPORTED_RESIZE_MODES = ['default', 'auto', 'fit', 'fill'];
    private const MIN_RESPONSIVE_WIDTH = 500;
    private const MIN_RESPONSIVE_HEIGHT = 300;
    private const IMAGE_URL_ATTRIBUTES = ['src', 'data-src', 'data-lazy-src', 'data-original'];
    private const IMAGE_SRCSET_ATTRIBUTES = ['srcset', 'data-srcset', 'data-lazy-srcset'];

    private array $options = [];
    private array $rewriteCache = [];

    public static function boot(): void
    {
        $plugin = new self();
        add_action('admin_menu', [$plugin, 'addSettingsPage']);
        add_action('admin_init', [$plugin, 'registerSettings']);
        add_action('admin_footer-plugins.php', [$plugin, 'renderPluginsListBranding']);
        add_action('template_redirect', [$plugin, 'startBuffer'], 0);
        add_filter('plugin_action_links_' . plugin_basename(__FILE__), [$plugin, 'addPluginLinks']);
    }

    public function __construct()
    {
        $this->options = $this->getOptions();
    }

    public function addSettingsPage(): void
    {
        add_menu_page(
            __('WebPix Optimizer', 'webpix-optimizer'),
            __('WebPix', 'webpix-optimizer'),
            'manage_options',
            'webpix-optimizer',
            [$this, 'renderSettingsPage'],
            $this->getMenuIcon(),
            58
        );
    }

    public function registerSettings(): void
    {
        register_setting('webpix_optimizer', self::OPTION_NAME, [$this, 'sanitizeOptions']);
    }

    public function addPluginLinks(array $links): array
    {
        $settingsLink = sprintf(
            '<a href="%s">%s</a>',
            esc_url(admin_url('admin.php?page=webpix-optimizer')),
            esc_html__('Settings', 'webpix-optimizer')
        );

        array_unshift($links, $settingsLink);
        return $links;
    }

    public function renderSettingsPage(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $options = $this->getOptions();
        ?>
        <div class="wrap webpix-admin">
            <?php $this->renderAdminStyles(); ?>
            <div class="webpix-admin__hero">
                <div>
                    <?php echo $this->getLogoSvg(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                    <h1><?php echo esc_html__('WebPix Optimizer for WordPress', 'webpix-optimizer'); ?></h1>
                    <p><?php echo esc_html__('Speed up WordPress by routing public images, SVG files, CSS and JavaScript through WebPix CDN. The plugin rewrites frontend asset URLs automatically, so you can reduce page weight, serve WebP or AVIF images, and improve Core Web Vitals without editing theme templates.', 'webpix-optimizer'); ?></p>
                    <a href="https://webpix.io/integrations/wordpress" target="_blank" rel="noopener"><?php echo esc_html__('Open WordPress integration guide', 'webpix-optimizer'); ?></a>
                </div>
                <div class="webpix-admin__status">
                    <span><?php echo esc_html__('Status', 'webpix-optimizer'); ?></span>
                    <strong data-webpix-status><?php echo !empty($options['enabled']) ? esc_html__('Enabled', 'webpix-optimizer') : esc_html__('Disabled', 'webpix-optimizer'); ?></strong>
                </div>
            </div>

            <form method="post" action="options.php">
                <?php settings_fields('webpix_optimizer'); ?>

                <section class="webpix-panel">
                    <h2><?php echo esc_html__('General', 'webpix-optimizer'); ?></h2>
                    <table class="form-table" role="presentation">
                        <?php $this->renderSwitcher('enabled', __('Enable WebPix optimization', 'webpix-optimizer'), $options, __('Turns on frontend URL rewriting. Keep it disabled while adding credentials or testing settings.', 'webpix-optimizer')); ?>
                        <?php $this->renderSwitcher('custom_cdn', __('Use custom CDN hostname', 'webpix-optimizer'), $options, __('Use your own media domain instead of cdn.webpix.io. Configure DNS in WebPix before enabling this.', 'webpix-optimizer'), 'enabled'); ?>
                        <?php $this->renderText('custom_hostname', __('Custom CDN Hostname', 'webpix-optimizer'), $options, 'media.example.com', __('Enter hostname only, without https://. Example: media.example.com.', 'webpix-optimizer'), 'custom_cdn'); ?>
                    </table>
                </section>

                <section class="webpix-panel" data-webpix-panel="enabled">
                    <h2><?php echo esc_html__('Access credentials', 'webpix-optimizer'); ?></h2>
                    <p class="webpix-panel__intro"><?php echo esc_html__('Copy these values from your WebPix subscription. Secret values are used only to sign or encrypt generated CDN URLs.', 'webpix-optimizer'); ?></p>
                    <table class="form-table" role="presentation">
                        <?php $this->renderText('cloud_name', __('Cloud Name', 'webpix-optimizer'), $options, 'your-cloud-name', __('Public account identifier used in every WebPix URL as k_cloud-name.', 'webpix-optimizer'), 'enabled'); ?>
                        <?php $this->renderPassword('secret_key', __('Secret Key', 'webpix-optimizer'), $options, __('Used with Secret Pin to create signed s1_ URLs.', 'webpix-optimizer'), 'enabled'); ?>
                        <?php $this->renderPassword('secret_pin', __('Secret Pin', 'webpix-optimizer'), $options, __('Second signing value. Keep it private and do not expose it in templates.', 'webpix-optimizer'), 'enabled'); ?>
                        <?php $this->renderPassword('encrypt_key', __('Encrypt Key', 'webpix-optimizer'), $options, __('Used for encrypted e1_ URLs. Recommended for production so original asset paths are hidden.', 'webpix-optimizer'), 'enabled'); ?>
                    </table>
                </section>

                <section class="webpix-panel" data-webpix-panel="enabled">
                    <h2><?php echo esc_html__('Images and SVG', 'webpix-optimizer'); ?></h2>
                    <table class="form-table" role="presentation">
                        <?php $this->renderSwitcher('optimize_images', __('Optimize images', 'webpix-optimizer'), $options, __('Rewrite JPG, PNG, WebP, AVIF and other image URLs through the WebPix image endpoint.', 'webpix-optimizer'), 'enabled'); ?>
                        <?php $this->renderSwitcher('secure_images', __('Use encrypted image URLs', 'webpix-optimizer'), $options, __('Generate e1_ image URLs instead of visible source paths. Recommended for live sites.', 'webpix-optimizer'), 'optimize_images'); ?>
                        <?php $this->renderNumber('quality', __('Image quality', 'webpix-optimizer'), $options, 1, 100, __('Compression quality from 1 to 100. Start with 75 for most WordPress sites.', 'webpix-optimizer'), 'optimize_images'); ?>
                        <?php $this->renderSelect('format', __('Output format', 'webpix-optimizer'), $options, ['webp' => 'WebP', 'avif' => 'AVIF', 'jpeg' => 'JPEG'], __('Modern browser output format. WebP is the safest default; AVIF can reduce size further.', 'webpix-optimizer'), 'optimize_images'); ?>
                        <?php $this->renderSelect('resize_mode', __('Resize mode', 'webpix-optimizer'), $options, ['default' => 'Default', 'fit' => 'Fit', 'fill' => 'Fill', 'auto' => 'Auto'], __('Default keeps proportions and avoids unexpected crops. Fit is best for most theme images.', 'webpix-optimizer'), 'optimize_images'); ?>
                        <?php $this->renderSwitcher('auto_dimensions', __('Add missing image width and height', 'webpix-optimizer'), $options, __('Automatically adds width and height attributes when WordPress image dimensions can be detected from attributes, inline styles, filenames or local uploads.', 'webpix-optimizer'), 'optimize_images'); ?>
                        <?php $this->renderSwitcher('responsive_srcset', __('Enable Responsive Image Srcset', 'webpix-optimizer'), $options, __('Add WebPix srcset and sizes attributes to eligible images so browsers can download a better sized asset.', 'webpix-optimizer'), 'optimize_images'); ?>
                        <?php $this->renderSwitcher('listing_lcp', __('Enable Listing LCP Image Optimization', 'webpix-optimizer'), $options, __('Prioritize the first visible listing or content image by disabling lazy loading and adding fetchpriority high.', 'webpix-optimizer'), 'optimize_images'); ?>
                        <?php $this->renderSwitcher('optimize_svg', __('Optimize SVG files', 'webpix-optimizer'), $options, __('Route SVG logos and icons through the WebPix SVG endpoint. SVG files remain SVG.', 'webpix-optimizer'), 'enabled'); ?>
                    </table>
                </section>

                <section class="webpix-panel" data-webpix-panel="enabled">
                    <h2><?php echo esc_html__('CSS and JavaScript', 'webpix-optimizer'); ?></h2>
                    <table class="form-table" role="presentation">
                        <?php $this->renderSwitcher('optimize_css', __('Optimize CSS files', 'webpix-optimizer'), $options, __('Rewrite stylesheet links through WebPix. Enable after image optimization is confirmed.', 'webpix-optimizer'), 'enabled'); ?>
                        <?php $this->renderSwitcher('secure_css', __('Use encrypted CSS URLs', 'webpix-optimizer'), $options, __('Generate encrypted e1_ CSS links and hide the original stylesheet path.', 'webpix-optimizer'), 'optimize_css'); ?>
                        <?php $this->renderSwitcher('optimize_js', __('Optimize JavaScript files', 'webpix-optimizer'), $options, __('Rewrite script links through WebPix. Test carefully if the site has strict CSP or custom script loading.', 'webpix-optimizer'), 'enabled'); ?>
                        <?php $this->renderSwitcher('secure_js', __('Use encrypted JavaScript URLs', 'webpix-optimizer'), $options, __('Generate encrypted e1_ JavaScript links and hide the original script path.', 'webpix-optimizer'), 'optimize_js'); ?>
                    </table>
                </section>

                <section class="webpix-panel" data-webpix-panel="enabled">
                    <h2><?php echo esc_html__('Fonts', 'webpix-optimizer'); ?></h2>
                    <p class="webpix-panel__intro"><?php echo esc_html__('Optimize Google Fonts loading behavior by adding or replacing the display strategy in fonts.googleapis.com URLs.', 'webpix-optimizer'); ?></p>
                    <table class="form-table" role="presentation">
                        <?php $this->renderSwitcher('optimize_fonts', __('Optimize Google Fonts', 'webpix-optimizer'), $options, __('Normalize Google Fonts URLs so text renders faster and font loading behavior is predictable.', 'webpix-optimizer'), 'enabled'); ?>
                        <?php $this->renderSelect('font_display', __('Display strategy', 'webpix-optimizer'), $options, ['swap' => 'Swap', 'optional' => 'Optional'], __('Swap shows fallback text immediately and replaces it when the font loads. Optional can reduce layout shifts on slower connections.', 'webpix-optimizer'), 'optimize_fonts'); ?>
                        <?php $this->renderSwitcher('force_font_display', __('Override existing display parameter', 'webpix-optimizer'), $options, __('Replace any existing display value in Google Fonts URLs with the strategy selected above.', 'webpix-optimizer'), 'optimize_fonts'); ?>
                    </table>
                </section>

                <div class="webpix-actions">
                    <?php submit_button(__('Save WebPix settings', 'webpix-optimizer'), 'primary', 'submit', false); ?>
                </div>
            </form>
            <?php $this->renderAdminScripts(); ?>
        </div>
        <?php
    }

    public function sanitizeOptions($input): array
    {
        $input = is_array($input) ? $input : [];
        $defaults = $this->defaultOptions();
        $output = [];

        foreach (['enabled', 'custom_cdn', 'optimize_images', 'secure_images', 'auto_dimensions', 'responsive_srcset', 'listing_lcp', 'optimize_svg', 'optimize_css', 'secure_css', 'optimize_js', 'secure_js', 'optimize_fonts', 'force_font_display'] as $key) {
            $output[$key] = !empty($input[$key]) ? 1 : 0;
        }

        foreach (['custom_hostname', 'cloud_name', 'secret_key', 'secret_pin', 'encrypt_key'] as $key) {
            $output[$key] = sanitize_text_field((string)($input[$key] ?? ''));
        }

        $quality = (int)($input['quality'] ?? $defaults['quality']);
        $output['quality'] = max(1, min(100, $quality));

        $format = strtolower(sanitize_key((string)($input['format'] ?? $defaults['format'])));
        $output['format'] = in_array($format, self::SUPPORTED_IMAGE_FORMATS, true) ? $format : $defaults['format'];

        $resizeMode = strtolower(sanitize_key((string)($input['resize_mode'] ?? $defaults['resize_mode'])));
        $output['resize_mode'] = in_array($resizeMode, self::SUPPORTED_RESIZE_MODES, true) ? $resizeMode : $defaults['resize_mode'];

        $fontDisplay = strtolower(sanitize_key((string)($input['font_display'] ?? $defaults['font_display'])));
        $output['font_display'] = in_array($fontDisplay, ['swap', 'optional'], true) ? $fontDisplay : $defaults['font_display'];

        return array_merge($defaults, $output);
    }

    public function startBuffer(): void
    {
        if (
            is_admin()
            || wp_doing_ajax()
            || (function_exists('wp_is_json_request') && wp_is_json_request())
            || !$this->isConfigured()
        ) {
            return;
        }

        ob_start([$this, 'rewriteHtml']);
    }

    public function rewriteHtml(string $html): string
    {
        if ($html === '' || stripos($html, '<html') === false) {
            return $html;
        }

        if (!empty($this->options['optimize_fonts']) && stripos($html, 'fonts.googleapis.com') !== false) {
            $html = $this->rewriteGoogleFonts($html);
        }

        if (!empty($this->options['optimize_css']) && stripos($html, '<link') !== false) {
            $html = $this->rewriteStylesheets($html);
        }

        if (!empty($this->options['optimize_js']) && stripos($html, '<script') !== false) {
            $html = $this->rewriteScripts($html);
        }

        if ((!empty($this->options['optimize_images']) || !empty($this->options['optimize_svg'])) && stripos($html, 'src') !== false) {
            $html = $this->rewriteImages($html);
            $html = $this->rewriteSrcsets($html);
        }

        if (!empty($this->options['listing_lcp']) && !empty($this->options['optimize_images']) && stripos($html, '<img') !== false) {
            $html = $this->optimizeListingLcpImage($html);
        }

        if ((!empty($this->options['optimize_images']) || !empty($this->options['optimize_svg'])) && strpos($html, 'url(') !== false) {
            $html = $this->rewriteCssUrls($html);
        }

        return $html;
    }

    private function rewriteStylesheets(string $html): string
    {
        return (string)preg_replace_callback(
            '/<link\b(?=[^>]*\brel=["\'][^"\']*stylesheet[^"\']*["\'])[^>]*\bhref=["\']([^"\']+)["\'][^>]*>/i',
            function (array $matches): string {
                return str_replace($matches[1], $this->buildAssetUrl($matches[1], 'css'), $matches[0]);
            },
            $html
        );
    }

    private function rewriteScripts(string $html): string
    {
        return (string)preg_replace_callback(
            '/<script\b[^>]*\bsrc=["\']([^"\']+)["\'][^>]*><\/script>/i',
            function (array $matches): string {
                return str_replace($matches[1], $this->buildAssetUrl($matches[1], 'js'), $matches[0]);
            },
            $html
        );
    }

    private function rewriteImages(string $html): string
    {
        return (string)preg_replace_callback(
            '/<img\b[^>]*>/i',
            function (array $matches): string {
                $tag = $matches[0];
                $originalSrc = $this->extractImageSource($tag);
                $dimensions = $this->extractDimensions($tag, $originalSrc);

                $tag = $this->rewriteImageUrlAttributes($tag, $dimensions);
                $tag = $this->rewriteImageSrcsetAttributes($tag);

                if (!empty($this->options['auto_dimensions'])) {
                    $tag = $this->addMissingImageDimensions($tag, $dimensions);
                }

                if (
                    !empty($this->options['responsive_srcset'])
                    && stripos($tag, 'srcset=') === false
                ) {
                    $tag = $this->addResponsiveSrcset($tag, $dimensions, $originalSrc);
                }

                return $tag;
            },
            $html
        );
    }

    private function rewriteSrcsets(string $html): string
    {
        return (string)preg_replace_callback(
            $this->attributePattern(self::IMAGE_SRCSET_ATTRIBUTES),
            fn(array $matches): string => $matches[1] . $this->rewriteSrcsetValue($matches[2]) . $matches[3],
            $html
        );
    }

    private function rewriteImageUrlAttributes(string $tag, array $dimensions): string
    {
        return (string)preg_replace_callback(
            $this->attributePattern(self::IMAGE_URL_ATTRIBUTES),
            function (array $matches) use ($dimensions): string {
                return $matches[1] . $this->buildImageUrl($matches[2], $dimensions['width'], $dimensions['height']) . $matches[3];
            },
            $tag
        );
    }

    private function rewriteImageSrcsetAttributes(string $tag): string
    {
        return (string)preg_replace_callback(
            $this->attributePattern(self::IMAGE_SRCSET_ATTRIBUTES),
            fn(array $matches): string => $matches[1] . $this->rewriteSrcsetValue($matches[2]) . $matches[3],
            $tag
        );
    }

    private function rewriteSrcsetValue(string $srcset): string
    {
        $parts = explode(',', $srcset);
        $rewritten = [];

        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }

            $subParts = preg_split('/\s+/', $part, 2);
            $url = $subParts[0] ?? '';
            $descriptor = trim($subParts[1] ?? '');
            $width = 0;

            if ($descriptor !== '' && preg_match('/^([0-9]+)w$/i', $descriptor, $widthMatch)) {
                $width = (int)$widthMatch[1];
            }

            $rewritten[] = trim(str_replace(',', '%2C', $this->buildImageUrl($url, $width, 0)) . ' ' . $descriptor);
        }

        return esc_attr(implode(', ', $rewritten));
    }

    private function addResponsiveSrcset(string $tag, array $dimensions, string $src): string
    {
        if ($src === '') {
            return $tag;
        }

        $origin = $this->extractOrigin($src);
        if ($origin === null || $origin['extension'] === 'svg') {
            return $tag;
        }

        $originalWidth = (int)($dimensions['width'] ?? 0);
        $originalHeight = (int)($dimensions['height'] ?? 0);
        if (!$this->isResponsiveImageCandidate($tag, $origin['path'], $originalWidth, $originalHeight)) {
            return $tag;
        }

        $widths = $this->getResponsiveWidths($originalWidth);
        if ($widths === []) {
            return $tag;
        }

        $srcset = [];
        foreach ($widths as $width) {
            $height = $this->scaleHeight($width, $originalWidth, $originalHeight);
            $srcset[] = str_replace(',', '%2C', $this->buildImageUrl($src, $width, $height)) . ' ' . $width . 'w';
        }

        $srcsetAttribute = $this->hasLazyImageSource($tag) ? 'data-srcset' : 'srcset';
        $attributes = ' ' . $srcsetAttribute . '="' . esc_attr(implode(', ', $srcset)) . '"';
        if (stripos($tag, ' sizes=') === false) {
            $sizes = $originalWidth > 0 ? '(max-width: ' . $originalWidth . 'px) 100vw, ' . $originalWidth . 'px' : '100vw';
            $attributes .= ' sizes="' . esc_attr($sizes) . '"';
        }

        return (string)preg_replace('/\s*\/?>$/', $attributes . '$0', $tag, 1);
    }

    private function addMissingImageDimensions(string $tag, array $dimensions): string
    {
        $width = (int)($dimensions['width'] ?? 0);
        $height = (int)($dimensions['height'] ?? 0);

        if ($width <= 0 || $height <= 0) {
            return $tag;
        }

        $attributes = '';
        if (stripos($tag, ' width=') === false) {
            $attributes .= ' width="' . $width . '"';
        }

        if (stripos($tag, ' height=') === false) {
            $attributes .= ' height="' . $height . '"';
        }

        if ($attributes === '') {
            return $tag;
        }

        return (string)preg_replace('/\s*\/?>$/', $attributes . '$0', $tag, 1);
    }

    private function isResponsiveImageCandidate(string $tag, string $path, int $width, int $height): bool
    {
        $tagLower = strtolower($tag);
        $pathLower = strtolower($path);

        if (
            strpos($tagLower, 'logo') !== false
            || strpos($tagLower, 'icon') !== false
            || strpos($tagLower, 'avatar') !== false
            || strpos($tagLower, 'badge') !== false
            || strpos($tagLower, 'sprite') !== false
            || strpos($tagLower, 'emoji') !== false
            || strpos($pathLower, 'logo') !== false
            || strpos($pathLower, 'icon') !== false
            || strpos($pathLower, 'avatar') !== false
            || strpos($pathLower, 'sprite') !== false
        ) {
            return false;
        }

        if ($width > 0 || $height > 0) {
            return $width >= self::MIN_RESPONSIVE_WIDTH && $height >= self::MIN_RESPONSIVE_HEIGHT;
        }

        return strpos($pathLower, '/uploads/') !== false
            || strpos($tagLower, 'wp-post-image') !== false
            || strpos($tagLower, 'attachment-') !== false
            || strpos($tagLower, 'size-') !== false;
    }

    private function optimizeListingLcpImage(string $html): string
    {
        $optimized = false;

        return (string)preg_replace_callback(
            '/<img\b[^>]*>/i',
            function (array $matches) use (&$optimized): string {
                if ($optimized) {
                    return $matches[0];
                }

                $tag = $matches[0];
                $src = $this->extractImageSource($tag);
                $origin = $this->extractOrigin($src);
                if ($origin === null || $origin['extension'] === 'svg') {
                    return $tag;
                }

                $tag = preg_replace('/\sloading=["\']lazy["\']/i', '', $tag) ?: $tag;
                if (stripos($tag, 'fetchpriority=') === false) {
                    $tag = preg_replace('/<img\b/i', '<img fetchpriority="high"', $tag, 1) ?: $tag;
                }
                if (stripos($tag, 'decoding=') === false) {
                    $tag = preg_replace('/<img\b/i', '<img decoding="sync"', $tag, 1) ?: $tag;
                }

                $optimized = true;
                return $tag;
            },
            $html,
            1
        );
    }

    private function rewriteCssUrls(string $html): string
    {
        $scripts = [];
        $safeHtml = (string)preg_replace_callback(
            '/<script\b[^>]*>.*?<\/script>/is',
            function (array $matches) use (&$scripts): string {
                $placeholder = '<!--WPX_SCRIPT_' . count($scripts) . '-->';
                $scripts[$placeholder] = $matches[0];
                return $placeholder;
            },
            $html
        );

        $safeHtml = (string)preg_replace_callback(
            '/url\([\'"]?([^\'")]+)[\'"]?\)/i',
            function (array $matches): string {
                $originalUrl = trim($matches[1]);
                if (!$this->isSupportedImageUrl($originalUrl)) {
                    return $matches[0];
                }

                return str_replace($originalUrl, $this->buildImageUrl($originalUrl), $matches[0]);
            },
            $safeHtml
        );

        return $scripts !== [] ? strtr($safeHtml, $scripts) : $safeHtml;
    }

    private function rewriteGoogleFonts(string $html): string
    {
        $html = (string)preg_replace_callback(
            '/<link\b[^>]*\bhref=["\']([^"\']*fonts\.googleapis\.com[^"\']*)["\'][^>]*>/i',
            function (array $matches): string {
                $originalUrl = trim($matches[1]);
                if ($originalUrl === '') {
                    return $matches[0];
                }

                $updatedUrl = $this->rewriteGoogleFontsUrl($originalUrl);
                return $updatedUrl === $originalUrl ? $matches[0] : str_replace($originalUrl, $updatedUrl, $matches[0]);
            },
            $html
        );

        $html = (string)preg_replace_callback(
            '/(@import\s+url\(\s*[\"\']?)((?:https?:)?\/\/fonts\.googleapis\.com\/[^\"\')\s]+)([\"\']?\s*\))/i',
            fn(array $matches): string => $matches[1] . $this->rewriteGoogleFontsUrl(trim($matches[2])) . $matches[3],
            $html
        );

        return (string)preg_replace_callback(
            '/(@import\s+[\"\'])((?:https?:)?\/\/fonts\.googleapis\.com\/[^\"\')\s]+)([\"\'])/i',
            fn(array $matches): string => $matches[1] . $this->rewriteGoogleFontsUrl(trim($matches[2])) . $matches[3],
            $html
        );
    }

    private function rewriteGoogleFontsUrl(string $url): string
    {
        $cacheKey = 'font|' . $url;
        if (isset($this->rewriteCache[$cacheKey])) {
            return $this->rewriteCache[$cacheKey];
        }

        $decoded = html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $schemeLess = strpos($decoded, '//') === 0;
        $candidate = $schemeLess ? 'https:' . $decoded : $decoded;
        if (!preg_match('#^https?://#i', $candidate)) {
            return $this->rewriteCache[$cacheKey] = $url;
        }

        $parts = wp_parse_url($candidate);
        if (!is_array($parts) || empty($parts['host']) || strcasecmp((string)$parts['host'], 'fonts.googleapis.com') !== 0) {
            return $this->rewriteCache[$cacheKey] = $url;
        }

        $query = (string)($parts['query'] ?? '');
        if (empty($this->options['force_font_display']) && preg_match('/(?:^|&)display=[^&]*/i', $query)) {
            return $this->rewriteCache[$cacheKey] = $url;
        }

        $query = preg_replace('/(?:^|&)display=[^&]*/i', '', $query);
        $query = trim((string)$query, '&');
        $displayParam = 'display=' . rawurlencode((string)$this->options['font_display']);
        $parts['query'] = $query !== '' ? $query . '&' . $displayParam : $displayParam;

        $updated = $this->buildUrlFromParts($parts);
        if ($updated === '') {
            return $this->rewriteCache[$cacheKey] = $url;
        }

        if ($schemeLess) {
            $updated = preg_replace('#^https:#i', '', $updated) ?: $updated;
        }
        if (strpos($url, '&amp;') !== false) {
            $updated = str_replace('&', '&amp;', $updated);
        }

        return $this->rewriteCache[$cacheKey] = $updated;
    }

    private function buildImageUrl(string $url, int $width = 0, int $height = 0): string
    {
        $cacheKey = 'img|' . $url . '|w=' . max(0, $width) . '|h=' . max(0, $height);
        if (isset($this->rewriteCache[$cacheKey])) {
            return $this->rewriteCache[$cacheKey];
        }

        $origin = $this->extractOrigin($url);
        if ($origin === null || $this->isWebpixUrl($url)) {
            return $this->rewriteCache[$cacheKey] = $url;
        }

        if ($origin['extension'] === 'svg') {
            if (empty($this->options['optimize_svg'])) {
                return $this->rewriteCache[$cacheKey] = $url;
            }

            return $this->rewriteCache[$cacheKey] = $this->buildSvgUrl($origin['host'], $origin['path']);
        }

        if (empty($this->options['optimize_images'])) {
            return $this->rewriteCache[$cacheKey] = $url;
        }

        $quality = (int)$this->options['quality'];
        $format = (string)$this->options['format'];
        $resizeMode = (string)$this->options['resize_mode'];
        if ($resizeMode === 'default') {
            $resizeMode = 'fit';
            $height = 0;
        }

        $source = $origin['host'] . '/' . ltrim($origin['path'], '/');
        $sourcePath = $this->buildImageSourcePath($source, $width, $height, $quality, $format, $resizeMode);
        $kid = (string)$this->options['cloud_name'];

        if (!empty($this->options['secure_images'])) {
            $token = $this->encryptToken('/img/' . $sourcePath, 'img', '/img/');
            if ($token !== '') {
                return $this->rewriteCache[$cacheKey] = $this->getCdnHost() . '/img/k_' . rawurlencode($kid) . '/e1_' . $token;
            }
        }

        $payload = sprintf('v=1|t=img|k=%s|w=%d|h=%d|q=%d|f=%s|rs=%s|%s', $kid, $width, $height, $quality, $format, $resizeMode, $source);
        $signature = $this->signPayload($payload);

        return $this->rewriteCache[$cacheKey] = $this->getCdnHost() . '/img/k_' . rawurlencode($kid) . '/s1_' . $signature . '/' . $sourcePath;
    }

    private function buildSvgUrl(string $host, string $path): string
    {
        $kid = (string)$this->options['cloud_name'];
        $source = trim($host) . '/' . ltrim(trim($path), '/');

        if (!empty($this->options['secure_images'])) {
            $token = $this->encryptToken('/svg/' . $source, 'svg', '/svg/');
            if ($token !== '') {
                return $this->getCdnHost() . '/svg/k_' . rawurlencode($kid) . '/e1_' . $token;
            }
        }

        $payload = sprintf('v=1|t=svg|k=%s|%s', $kid, $source);
        return $this->getCdnHost() . '/svg/k_' . rawurlencode($kid) . '/s1_' . $this->signPayload($payload) . '/' . $source;
    }

    private function buildAssetUrl(string $url, string $type): string
    {
        $cacheKey = $type . '|' . $url;
        if (isset($this->rewriteCache[$cacheKey])) {
            return $this->rewriteCache[$cacheKey];
        }

        if ($this->isWebpixUrl($url) || stripos($url, 'data:') === 0) {
            return $this->rewriteCache[$cacheKey] = $url;
        }

        $origin = $this->extractOrigin($url);
        if ($origin === null) {
            return $this->rewriteCache[$cacheKey] = $url;
        }

        $secureOption = $type === 'css' ? 'secure_css' : 'secure_js';
        $source = $origin['host'] . '/' . ltrim($origin['path'], '/');
        $query = $origin['query'];
        $kid = (string)$this->options['cloud_name'];
        $mode = 'proxy';

        if (!empty($this->options[$secureOption])) {
            $token = $this->encryptToken('/' . $type . '/a_' . $mode . '/' . $source, $type, '/' . $type . '/', $query);
            if ($token !== '') {
                return $this->rewriteCache[$cacheKey] = $this->getCdnHost() . '/' . $type . '/k_' . rawurlencode($kid) . '/e1_' . $token;
            }
        }

        $payload = sprintf('v=1|t=%s|k=%s|a=%s|sq=%s|%s', $type, $kid, $mode, $query !== '' ? $query : '-', $source);
        $result = $this->getCdnHost() . '/' . $type . '/k_' . rawurlencode($kid) . '/s1_' . $this->signPayload($payload) . '/a_' . $mode . '/' . $source;
        if ($query !== '') {
            $result .= '?' . $query;
        }

        return $this->rewriteCache[$cacheKey] = $result;
    }

    private function extractOrigin(string $url): ?array
    {
        $url = html_entity_decode(trim($url), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if ($url === '' || preg_match('#^(data:|blob:|mailto:|tel:|javascript:)#i', $url)) {
            return null;
        }

        if (strpos($url, '//') === 0) {
            $url = (is_ssl() ? 'https:' : 'http:') . $url;
        }

        if ($url[0] === '/') {
            $url = home_url($url);
        }

        $parsed = wp_parse_url($url);
        if (!is_array($parsed) || empty($parsed['host']) || empty($parsed['path'])) {
            return null;
        }

        $path = '/' . ltrim((string)$parsed['path'], '/');

        return [
            'host' => strtolower(trim((string)$parsed['host'])),
            'path' => $path,
            'query' => trim((string)($parsed['query'] ?? '')),
            'extension' => strtolower(pathinfo($path, PATHINFO_EXTENSION)),
        ];
    }

    private function isSupportedImageUrl(string $url): bool
    {
        $origin = $this->extractOrigin($url);
        return $origin !== null && in_array($origin['extension'], self::SUPPORTED_IMAGE_EXTENSIONS, true);
    }

    private function isWebpixUrl(string $url): bool
    {
        $host = (string)wp_parse_url($this->getCdnHost(), PHP_URL_HOST);
        $urlHost = (string)wp_parse_url(trim($url), PHP_URL_HOST);
        return $host !== '' && $urlHost !== '' && strcasecmp($host, $urlHost) === 0;
    }

    private function buildImageSourcePath(string $source, int $width, int $height, int $quality, string $format, string $resizeMode): string
    {
        $params = [];
        if ($width > 0) {
            $params[] = 'w_' . $width;
        }
        if ($height > 0) {
            $params[] = 'h_' . $height;
        }
        $params[] = 'q_' . $quality;
        $params[] = 'f_' . $format;
        if ($resizeMode !== 'auto') {
            $params[] = 'rs_' . $resizeMode;
        }

        return implode(',', $params) . '/' . $source;
    }

    private function buildUrlFromParts(array $parts): string
    {
        if (empty($parts['host'])) {
            return '';
        }

        $scheme = isset($parts['scheme']) ? (string)$parts['scheme'] . '://' : 'https://';
        $user = (string)($parts['user'] ?? '');
        $pass = (string)($parts['pass'] ?? '');
        $auth = $user !== '' ? $user . ($pass !== '' ? ':' . $pass : '') . '@' : '';
        $host = (string)$parts['host'];
        $port = isset($parts['port']) ? ':' . (int)$parts['port'] : '';
        $path = isset($parts['path']) ? (string)$parts['path'] : '';
        $query = isset($parts['query']) && $parts['query'] !== '' ? '?' . (string)$parts['query'] : '';
        $fragment = isset($parts['fragment']) && $parts['fragment'] !== '' ? '#' . (string)$parts['fragment'] : '';

        return $scheme . $auth . $host . $port . $path . $query . $fragment;
    }

    private function signPayload(string $payload): string
    {
        $digest = hash_hmac('sha256', (string)$this->options['secret_pin'] . '|' . $payload, (string)$this->options['secret_key'], true);
        return $this->base64UrlEncode(substr($digest, 0, 12));
    }

    private function encryptToken(string $path, string $target, string $prefix, string $query = ''): string
    {
        $secretKey = (string)$this->options['secret_key'];
        $secretPin = (string)$this->options['secret_pin'];
        $encryptKey = (string)$this->options['encrypt_key'];
        $keyMaterial = $encryptKey !== ''
            ? hash('sha256', $encryptKey, true)
            : hash('sha256', $secretKey . '|' . $secretPin . '|enc', true);

        $aad = 'webpix:e1:t=' . $target . ':k=' . (string)$this->options['cloud_name'] . ':p=' . $prefix;
        $payloadData = ['p' => $path];
        if ($query !== '') {
            $payloadData['q'] = $query;
        }

        $payload = wp_json_encode($payloadData, JSON_UNESCAPED_SLASHES);
        if (!is_string($payload)) {
            return '';
        }

        $nonce = substr(hash_hmac('sha256', $aad . '|' . $payload, $keyMaterial, true), 0, 12);
        $tag = '';
        $ciphertext = openssl_encrypt($payload, 'aes-256-gcm', $keyMaterial, OPENSSL_RAW_DATA, $nonce, $tag, $aad);
        if ($ciphertext === false) {
            return '';
        }

        return $this->base64UrlEncode(chr(1) . $nonce . $ciphertext . $tag);
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function extractDimensions(string $tag, string $src = ''): array
    {
        $width = $this->extractPositiveIntAttribute($tag, 'width');
        $height = $this->extractPositiveIntAttribute($tag, 'height');

        if ($width <= 0) {
            $width = $this->extractPositiveIntAttribute($tag, 'data-width');
        }

        if ($height <= 0) {
            $height = $this->extractPositiveIntAttribute($tag, 'data-height');
        }

        if (($width <= 0 || $height <= 0) && preg_match('/\sstyle=["\']([^"\']+)["\']/i', $tag, $styleMatch)) {
            $style = $styleMatch[1];

            if ($width <= 0 && preg_match('/(?:^|;)\s*(?:width|max-width)\s*:\s*([0-9]+)(?:px)?\b/i', $style, $widthMatch)) {
                $width = max(0, (int)$widthMatch[1]);
            }

            if ($height <= 0 && preg_match('/(?:^|;)\s*(?:height|max-height)\s*:\s*([0-9]+)(?:px)?\b/i', $style, $heightMatch)) {
                $height = max(0, (int)$heightMatch[1]);
            }
        }

        if (($width <= 0 || $height <= 0) && $src !== '') {
            $urlPath = parse_url(html_entity_decode($src, ENT_QUOTES, 'UTF-8'), PHP_URL_PATH);
            $path = $urlPath ? $urlPath : $src;

            if (preg_match('/(?:^|[-_\/])([1-9][0-9]{0,4})x([1-9][0-9]{0,4})(?=[^0-9]|$)/i', $path, $sizeMatch)) {
                if ($width <= 0) {
                    $width = (int)$sizeMatch[1];
                }

                if ($height <= 0) {
                    $height = (int)$sizeMatch[2];
                }
            }
        }

        if (($width <= 0 || $height <= 0) && $src !== '') {
            $localDimensions = $this->getLocalImageDimensions($src);
            if ($width <= 0) {
                $width = $localDimensions['width'];
            }
            if ($height <= 0) {
                $height = $localDimensions['height'];
            }
        }

        return [
            'width' => $width,
            'height' => $height,
        ];
    }

    private function getLocalImageDimensions(string $src): array
    {
        $empty = ['width' => 0, 'height' => 0];
        $path = (string)parse_url(html_entity_decode($src, ENT_QUOTES, 'UTF-8'), PHP_URL_PATH);
        if ($path === '' || strpos($path, '/wp-content/uploads/') === false || !function_exists('wp_upload_dir')) {
            return $empty;
        }

        $uploads = wp_upload_dir();
        $baseUrlPath = (string)parse_url((string)($uploads['baseurl'] ?? ''), PHP_URL_PATH);
        $baseDir = (string)($uploads['basedir'] ?? '');
        if ($baseUrlPath === '' || $baseDir === '' || strpos($path, $baseUrlPath) !== 0) {
            return $empty;
        }

        $relative = ltrim(substr($path, strlen($baseUrlPath)), '/');
        $file = rtrim($baseDir, '/\\') . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relative);
        if (!is_file($file)) {
            return $empty;
        }

        $size = @getimagesize($file);
        if (!is_array($size) || empty($size[0]) || empty($size[1])) {
            return $empty;
        }

        return ['width' => (int)$size[0], 'height' => (int)$size[1]];
    }

    private function extractAttribute(string $tag, string $attribute): string
    {
        if (!preg_match('/\s' . preg_quote($attribute, '/') . '=["\']([^"\']+)["\']/i', $tag, $match)) {
            return '';
        }

        return trim((string)$match[1]);
    }

    private function extractImageSource(string $tag): string
    {
        $fallback = '';

        foreach (self::IMAGE_URL_ATTRIBUTES as $attribute) {
            $value = $this->extractAttribute($tag, $attribute);
            if ($value === '') {
                continue;
            }

            if ($fallback === '') {
                $fallback = $value;
            }

            if (stripos($value, 'data:') !== 0 && $this->extractOrigin($value) !== null) {
                return $value;
            }
        }

        return $fallback;
    }

    private function hasLazyImageSource(string $tag): bool
    {
        return $this->extractAttribute($tag, 'data-src') !== ''
            || $this->extractAttribute($tag, 'data-lazy-src') !== '';
    }

    private function attributePattern(array $attributes): string
    {
        $alternatives = array_map(
            static fn(string $attribute): string => preg_quote($attribute, '/'),
            $attributes
        );

        return '/(\s+(?:' . implode('|', $alternatives) . ')=["\'])([^"\']+)(["\'])/i';
    }

    private function extractPositiveIntAttribute(string $tag, string $attribute): int
    {
        if (!preg_match('/\s' . preg_quote($attribute, '/') . '=["\']?([0-9]+)(?:px)?["\']?/i', $tag, $match)) {
            return 0;
        }

        return max(0, (int)$match[1]);
    }

    private function getResponsiveWidths(int $originalWidth): array
    {
        $baseWidths = [320, 420, 560, 700, 960, 1200, 1440];
        $widths = [];

        foreach ($baseWidths as $width) {
            if ($originalWidth <= 0 || $width < $originalWidth) {
                $widths[] = $width;
            }
        }

        if ($originalWidth > 0) {
            $widths[] = $originalWidth;
        }

        return array_values(array_unique($widths));
    }

    private function scaleHeight(int $targetWidth, int $originalWidth, int $originalHeight): int
    {
        if ($targetWidth <= 0 || $originalWidth <= 0 || $originalHeight <= 0) {
            return 0;
        }

        return max(1, (int)round($originalHeight * ($targetWidth / $originalWidth)));
    }

    private function isConfigured(): bool
    {
        return !empty($this->options['enabled'])
            && $this->options['cloud_name'] !== ''
            && $this->options['secret_key'] !== ''
            && $this->options['secret_pin'] !== '';
    }

    private function getCdnHost(): string
    {
        if (!empty($this->options['custom_cdn']) && trim((string)$this->options['custom_hostname']) !== '') {
            return $this->normalizeHost((string)$this->options['custom_hostname']);
        }

        return self::DEFAULT_CDN_HOST;
    }

    private function normalizeHost(string $host): string
    {
        $host = trim($host);
        if ($host === '') {
            return '';
        }

        if (!preg_match('#^https?://#i', $host)) {
            $host = 'https://' . $host;
        }

        return rtrim($host, '/');
    }

    private function getOptions(): array
    {
        $stored = get_option(self::OPTION_NAME, []);
        return array_merge($this->defaultOptions(), is_array($stored) ? $stored : []);
    }

    private function defaultOptions(): array
    {
        return [
            'enabled' => 0,
            'custom_cdn' => 0,
            'custom_hostname' => '',
            'cloud_name' => '',
            'secret_key' => '',
            'secret_pin' => '',
            'encrypt_key' => '',
            'optimize_images' => 1,
            'secure_images' => 1,
            'auto_dimensions' => 1,
            'responsive_srcset' => 1,
            'listing_lcp' => 1,
            'quality' => 75,
            'format' => 'webp',
            'resize_mode' => 'default',
            'optimize_svg' => 1,
            'optimize_css' => 0,
            'secure_css' => 1,
            'optimize_js' => 0,
            'secure_js' => 1,
            'optimize_fonts' => 0,
            'font_display' => 'swap',
            'force_font_display' => 1,
        ];
    }

    private function renderSwitcher(string $key, string $label, array $options, string $description, string $dependsOn = ''): void
    {
        printf(
            '<tr class="webpix-field" data-webpix-field="%1$s"%2$s><th scope="row">%3$s</th><td><label class="webpix-switch"><input type="checkbox" name="%4$s[%1$s]" value="1" %5$s><span class="webpix-switch__track" aria-hidden="true"><span class="webpix-switch__thumb"></span></span><span class="webpix-switch__text">%6$s</span></label><p class="description">%7$s</p></td></tr>',
            esc_attr($key),
            $dependsOn !== '' ? ' data-webpix-depends="' . esc_attr($dependsOn) . '"' : '',
            esc_html($label),
            esc_attr(self::OPTION_NAME),
            checked(!empty($options[$key]), true, false),
            esc_html__('Enabled', 'webpix-optimizer'),
            esc_html($description)
        );
    }

    private function renderText(string $key, string $label, array $options, string $placeholder = '', string $description = '', string $dependsOn = ''): void
    {
        printf(
            '<tr class="webpix-field" data-webpix-field="%4$s"%7$s><th scope="row"><label for="%1$s">%2$s</label></th><td><input class="regular-text" id="%1$s" type="text" name="%3$s[%4$s]" value="%5$s" placeholder="%6$s">%8$s</td></tr>',
            esc_attr('webpix_' . $key),
            esc_html($label),
            esc_attr(self::OPTION_NAME),
            esc_attr($key),
            esc_attr((string)($options[$key] ?? '')),
            esc_attr($placeholder),
            $dependsOn !== '' ? ' data-webpix-depends="' . esc_attr($dependsOn) . '"' : '',
            $description !== '' ? '<p class="description">' . esc_html($description) . '</p>' : ''
        );
    }

    private function renderPassword(string $key, string $label, array $options, string $description = '', string $dependsOn = ''): void
    {
        printf(
            '<tr class="webpix-field" data-webpix-field="%4$s"%6$s><th scope="row"><label for="%1$s">%2$s</label></th><td><input class="regular-text" id="%1$s" type="password" autocomplete="off" name="%3$s[%4$s]" value="%5$s">%7$s</td></tr>',
            esc_attr('webpix_' . $key),
            esc_html($label),
            esc_attr(self::OPTION_NAME),
            esc_attr($key),
            esc_attr((string)($options[$key] ?? '')),
            $dependsOn !== '' ? ' data-webpix-depends="' . esc_attr($dependsOn) . '"' : '',
            $description !== '' ? '<p class="description">' . esc_html($description) . '</p>' : ''
        );
    }

    private function renderNumber(string $key, string $label, array $options, int $min, int $max, string $description = '', string $dependsOn = ''): void
    {
        printf(
            '<tr class="webpix-field" data-webpix-field="%4$s"%8$s><th scope="row"><label for="%1$s">%2$s</label></th><td><input id="%1$s" type="number" min="%6$d" max="%7$d" name="%3$s[%4$s]" value="%5$d">%9$s</td></tr>',
            esc_attr('webpix_' . $key),
            esc_html($label),
            esc_attr(self::OPTION_NAME),
            esc_attr($key),
            (int)($options[$key] ?? 75),
            $min,
            $max,
            $dependsOn !== '' ? ' data-webpix-depends="' . esc_attr($dependsOn) . '"' : '',
            $description !== '' ? '<p class="description">' . esc_html($description) . '</p>' : ''
        );
    }

    private function renderSelect(string $key, string $label, array $options, array $choices, string $description = '', string $dependsOn = ''): void
    {
        printf(
            '<tr class="webpix-field" data-webpix-field="%4$s"%5$s><th scope="row"><label for="%1$s">%2$s</label></th><td><select id="%1$s" name="%3$s[%4$s]">',
            esc_attr('webpix_' . $key),
            esc_html($label),
            esc_attr(self::OPTION_NAME),
            esc_attr($key),
            $dependsOn !== '' ? ' data-webpix-depends="' . esc_attr($dependsOn) . '"' : ''
        );

        foreach ($choices as $value => $choiceLabel) {
            printf(
                '<option value="%s" %s>%s</option>',
                esc_attr((string)$value),
                selected((string)($options[$key] ?? ''), (string)$value, false),
                esc_html((string)$choiceLabel)
            );
        }

        echo '</select>';
        if ($description !== '') {
            echo '<p class="description">' . esc_html($description) . '</p>';
        }
        echo '</td></tr>';
    }

    private function renderAdminStyles(): void
    {
        ?>
        <style>
            .webpix-admin { max-width: 1160px; }
            .webpix-admin__hero { margin: 22px 0 24px; padding: 26px; display: grid; grid-template-columns: minmax(0, 1fr) 180px; gap: 24px; align-items: start; background: #fff; border: 1px solid #dbe7f3; border-radius: 12px; box-shadow: 0 14px 36px rgba(31,25,96,.08); }
            .webpix-admin__hero svg { width: 200px; height: 48px; display: block; margin-bottom: 18px; }
            .webpix-admin__hero h1 { margin: 0 0 10px; color: #1f1960; font-size: 30px; line-height: 1.2; font-weight: 800; }
            .webpix-admin__hero p { max-width: 780px; margin: 0 0 14px; color: #344054; font-size: 15px; line-height: 1.65; }
            .webpix-admin__hero a { color: #0969da; font-weight: 700; text-decoration: none; }
            .webpix-admin__hero a:hover { text-decoration: underline; }
            .webpix-admin__status { padding: 16px; border-radius: 10px; background: #f1f8fc; border: 1px solid #cbeafe; }
            .webpix-admin__status span { display: block; margin-bottom: 5px; color: #667085; font-size: 12px; text-transform: uppercase; letter-spacing: .06em; }
            .webpix-admin__status strong { color: #1f1960; font-size: 20px; }
            .webpix-panel { margin: 0 0 18px; padding: 8px 22px 18px; background: #fff; border: 1px solid #dbe7f3; border-radius: 12px; }
            .webpix-panel h2 { margin: 15px 0 4px; color: #1f1960; font-size: 20px; }
            .webpix-panel__intro { margin: 0 0 8px; color: #667085; }
            .webpix-panel .form-table th { width: 250px; color: #1f1960; font-weight: 700; }
            .webpix-panel input.regular-text, .webpix-panel input[type=number], .webpix-panel select { min-height: 38px; border-color: #cfd9e5; border-radius: 8px; }
            .webpix-panel .description { max-width: 680px; margin-top: 7px; color: #667085; line-height: 1.5; }
            .webpix-switch { display: inline-flex; align-items: center; gap: 10px; min-height: 34px; }
            .webpix-switch input { position: absolute; opacity: 0; pointer-events: none; }
            .webpix-switch__track { position: relative; width: 54px; height: 30px; display: inline-flex; align-items: center; border-radius: 999px; background: #d0d5dd; transition: background .18s ease; box-shadow: inset 0 0 0 1px rgba(0,0,0,.06); }
            .webpix-switch__thumb { position: absolute; left: 3px; width: 24px; height: 24px; border-radius: 50%; background: #fff; box-shadow: 0 2px 8px rgba(16,24,40,.22); transition: transform .18s ease; }
            .webpix-switch input:checked + .webpix-switch__track { background: #62bae9; }
            .webpix-switch input:checked + .webpix-switch__track .webpix-switch__thumb { transform: translateX(24px); }
            .webpix-switch__text { color: #1f1960; font-weight: 700; }
            .webpix-field.is-hidden, .webpix-panel.is-hidden { display: none; }
            .webpix-actions { position: sticky; bottom: 0; z-index: 4; margin: 20px 0 0; padding: 14px 0; background: linear-gradient(to bottom, rgba(240,240,241,0), #f0f0f1 34%); }
            .webpix-actions .button-primary { min-height: 40px; padding: 0 18px; border-radius: 8px; background: #1f1960; border-color: #1f1960; font-weight: 700; }
            .webpix-actions .button-primary:hover { background: #332a85; border-color: #332a85; }
            @media (max-width: 782px) {
                .webpix-admin__hero { grid-template-columns: 1fr; padding: 20px; }
                .webpix-panel .form-table th { width: auto; }
            }
        </style>
        <?php
    }

    private function renderAdminScripts(): void
    {
        ?>
        <script>
            (function () {
                function isChecked(name) {
                    var input = document.querySelector('input[name="<?php echo esc_js(self::OPTION_NAME); ?>[' + name + ']"]');
                    return !!(input && input.checked);
                }

                function refresh() {
                    document.querySelectorAll('[data-webpix-depends]').forEach(function (row) {
                        row.classList.toggle('is-hidden', !isChecked(row.getAttribute('data-webpix-depends')));
                    });
                    document.querySelectorAll('[data-webpix-panel]').forEach(function (panel) {
                        panel.classList.toggle('is-hidden', !isChecked(panel.getAttribute('data-webpix-panel')));
                    });
                    var status = document.querySelector('[data-webpix-status]');
                    if (status) {
                        status.textContent = isChecked('enabled') ? 'Enabled' : 'Disabled';
                    }
                }

                document.querySelectorAll('.webpix-admin input[type="checkbox"]').forEach(function (input) {
                    input.addEventListener('change', refresh);
                });
                refresh();
            })();
        </script>
        <?php
    }

    public function renderPluginsListBranding(): void
    {
        $pluginFile = esc_js(plugin_basename(__FILE__));
        $logo = wp_json_encode($this->getCompactLogoSvg());
        if (!is_string($logo)) {
            return;
        }
        ?>
        <style>
            .plugins tr[data-plugin="<?php echo esc_attr(plugin_basename(__FILE__)); ?>"] .plugin-title strong {
                display: inline-flex;
                align-items: center;
                gap: 9px;
                color: #1f1960;
                font-size: 15px;
            }
            .plugins tr[data-plugin="<?php echo esc_attr(plugin_basename(__FILE__)); ?>"] .webpix-plugin-list-logo {
                width: 30px;
                height: 30px;
                display: inline-flex;
                align-items: center;
                justify-content: center;
                border-radius: 8px;
                background: #eef8fd;
                box-shadow: inset 0 0 0 1px #cbeafe;
            }
            .plugins tr[data-plugin="<?php echo esc_attr(plugin_basename(__FILE__)); ?>"] .webpix-plugin-list-logo svg {
                width: 24px;
                height: 24px;
                display: block;
            }
            .plugins tr[data-plugin="<?php echo esc_attr(plugin_basename(__FILE__)); ?>"] .plugin-description p:first-child {
                max-width: 720px;
                color: #344054;
                font-weight: 500;
                line-height: 1.55;
            }
        </style>
        <script>
            (function () {
                var row = document.querySelector('tr[data-plugin="' + <?php echo wp_json_encode($pluginFile); ?> + '"]');
                if (!row || row.classList.contains('webpix-plugin-list-branded')) {
                    return;
                }
                var title = row.querySelector('.plugin-title strong');
                if (!title) {
                    return;
                }
                var logoWrap = document.createElement('span');
                logoWrap.className = 'webpix-plugin-list-logo';
                logoWrap.setAttribute('aria-hidden', 'true');
                logoWrap.innerHTML = <?php echo $logo; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>;
                title.insertBefore(logoWrap, title.firstChild);
                row.classList.add('webpix-plugin-list-branded');
            })();
        </script>
        <?php
    }

    private function getLogoSvg(): string
    {
        return '<svg viewBox="0 0 200 48" fill="none" xmlns="http://www.w3.org/2000/svg" aria-label="WebPix"><path fill="#fff" fill-opacity=".01" d="M0 0h48v48H0z"/><path d="M20 4H4v16h16zm0 24H4v16h16zM44 4H28v16h16z" fill="#62bae9" stroke="#1f1960" stroke-width="4" stroke-linejoin="round"/><path d="M30.002 28v16M42 28v16" stroke="#1f1960" stroke-width="4" stroke-linecap="round"/><text x="56" y="38" font-family="Plus Jakarta Sans, Arial, sans-serif" font-weight="600" font-size="40" fill="#1b145d" letter-spacing="-1">WebPix</text></svg>';
    }

    private function getMenuIcon(): string
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 48 48"><path d="M20 4H4v16h16zm0 24H4v16h16zM44 4H28v16h16z" fill="#62bae9" stroke="#1f1960" stroke-width="4" stroke-linejoin="round"/><path d="M30 28v16M42 28v16" stroke="#1f1960" stroke-width="4" stroke-linecap="round"/></svg>';
        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }

    private function getCompactLogoSvg(): string
    {
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 48 48" fill="none"><path d="M20 4H4v16h16zm0 24H4v16h16zM44 4H28v16h16z" fill="#62bae9" stroke="#1f1960" stroke-width="4" stroke-linejoin="round"/><path d="M30.002 28v16M42 28v16" stroke="#1f1960" stroke-width="4" stroke-linecap="round"/></svg>';
    }
}

Webpix_Optimizer_Plugin::boot();
