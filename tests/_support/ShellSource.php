<?php
/**
 * ShellSource — the authenticated shell as one string.
 *
 * The shell is deliberately split: layouts/app.php and layouts/app_theme.php
 * render the chrome, layouts/_app_context.php builds the navigation tree,
 * branding and page defaults they share, and partials/impersonation_banner.php
 * carries the administrator-mode banner every shell must render. A test that
 * asks "does the nav link the VTU queue?" has to read the whole shell, not
 * whichever file the markup happened to live in on the day it was written.
 */
class ShellSource
{
    /** Every file that makes up the authenticated shell, concatenated. */
    public static function app($root)
    {
        $parts = array(
            '/application/views/layouts/app.php',
            '/application/views/layouts/_app_context.php',
            '/application/views/layouts/app_theme.php',
            '/application/views/partials/impersonation_banner.php',
            '/application/views/partials/navigation/sidebar.php',
            '/application/views/partials/theme/sidebar.php',
            '/application/views/partials/theme/dashboard_header.php',
        );
        $out = '';
        foreach ($parts as $file) {
            $path = rtrim($root, '/').$file;
            if (is_file($path)) $out .= file_get_contents($path)."\n";
        }
        return $out;
    }
}
