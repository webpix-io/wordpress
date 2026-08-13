# WebPix Optimizer for WordPress

WebPix Optimizer connects a WordPress site to WebPix CDN and rewrites public frontend asset URLs to optimized WebPix delivery URLs.

## Current features

- Image delivery through `/img`.
- SVG delivery through `/svg`.
- Responsive image `srcset` generation for eligible images.
- First visible image LCP priority optimization.
- CSS delivery through `/css`.
- JavaScript delivery through `/js`.
- Google Fonts display strategy normalization.
- Signed `s1_` URLs.
- Encrypted `e1_` URLs when enabled.
- Default `cdn.webpix.io` or custom CDN hostname.

## Installation

Copy the plugin folder to:

```text
wp-content/plugins/webpix-optimizer
```

Then activate it in WordPress admin:

```text
Plugins > WebPix Optimizer > Activate
```

Open settings:

```text
WebPix > WebPix Optimizer
```

Enter:

- Cloud Name
- Secret Key
- Secret Pin
- Encrypt Key

## Recommended first setup

Start with:

```text
Enabled = Yes
Optimize images = Yes
Use encrypted image URLs = Yes
Enable Responsive Image Srcset = Yes
Enable Listing LCP Image Optimization = Yes
Image quality = 75
Output format = WebP
Resize mode = Default
Optimize SVG files = Yes
Optimize CSS files = No
Optimize JavaScript files = No
Optimize Google Fonts = No
```

After images and SVG are confirmed, enable CSS, JavaScript and Google Fonts separately.

## Notes

WebPix must be able to download the original asset URL from the public internet. Local-only files or blocked hotlink URLs cannot be optimized remotely.

Responsive `srcset` is added only for larger content images. Small logos, icons, avatars, sprites and badges keep a single optimized `src`.
