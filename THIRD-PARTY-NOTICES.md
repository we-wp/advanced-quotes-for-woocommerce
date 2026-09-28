# Third-party notices

The combined release is distributed under GPL-3.0-or-later. Original upstream licences and notices remain included under `vendor/`.

| Component | Version | Upstream licence |
| --- | --- | --- |
| dompdf/dompdf | 3.1.6 | LGPL-2.1 |
| dompdf/php-font-lib | 1.0.2 | LGPL-2.1-or-later |
| dompdf/php-svg-lib | 1.0.2 | LGPL-3.0-or-later |
| masterminds/html5 | 2.11.0 | MIT |
| sabberworm/php-css-parser | 9.4.0 | MIT |
| thecodingmachine/safe | 3.4.0 | MIT |
| DejaVu fonts (included in dompdf/dompdf `lib/fonts`) | 2.37 | Bitstream Vera Fonts licence; DejaVu changes in the public domain |

The build transforms dependency namespaces to `WeWPQuotesVendor` with PHP-Scoper 0.18.18 to avoid collisions with other plugins, including Advanced Invoices for WooCommerce. It removes development executables and regenerates Composer autoload maps. Source versions and hashes are pinned in `composer.lock`.

The plugin bundles no fonts of its own. PDFs use DejaVu Sans, and add-on templates may also use DejaVu Sans Mono. Dompdf includes both in `vendor/dompdf/dompdf/lib/fonts`. `fonts/` holds only the metric caches that `tools/fonts.php` generates from those files. Online pages use the site's body font or the visitor's system font. The plugin requests no remote fonts.

Each DejaVu font file carries its copyright notice and full licence text in its font name table, with the licence address `http://dejavu.sourceforge.net/wiki/index.php/License`. The files are distributed unmodified.
