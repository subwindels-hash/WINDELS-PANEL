<?php defined('BASEPATH') OR exit('No direct script access allowed');
// Branded maintenance holding page, shown to non-staff while the operator has
// maintenance mode enabled. Staff sign in through /admin/login to keep working.
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Under maintenance · WINDELS PANEL</title>
<link rel="icon" href="/assets/brand/favicon.svg" type="image/svg+xml">
<style>
*{box-sizing:border-box}
body{margin:0;font-family:Inter,ui-sans-serif,system-ui,-apple-system,'Segoe UI',Roboto,sans-serif;color:#0f172a;background:radial-gradient(900px 280px at 12% -10%,#e0e7ff,transparent 60%),radial-gradient(700px 240px at 92% 0,#fae8ff,transparent 55%),#fff;line-height:1.6;-webkit-font-smoothing:antialiased}
.wrap{max-width:40rem;margin:0 auto;padding:14vh 1.25rem 4rem;text-align:center}
.brand{display:inline-flex;align-items:center;gap:.6rem;font-weight:700;letter-spacing:-.02em;margin-bottom:1.5rem;text-decoration:none;color:#0f172a}
.code{display:inline-block;font-family:ui-monospace,Menlo,Consolas,monospace;font-size:.8rem;font-weight:700;letter-spacing:.14em;text-transform:uppercase;color:#4f46e5;background:#eef2ff;border:1px solid #e0e7ff;border-radius:999px;padding:.3rem .8rem;margin-bottom:1rem}
h1{font-family:Georgia,'Times New Roman',serif;font-weight:600;font-size:clamp(1.6rem,4vw,2.2rem);margin:0 0 .6rem;letter-spacing:-.01em}
p{color:#475569;margin:0 auto 1.5rem;max-width:30rem}
.actions{display:flex;gap:.6rem;justify-content:center;flex-wrap:wrap}
.btn{display:inline-block;padding:.65rem 1.15rem;border-radius:.75rem;font-weight:600;font-size:.925rem;text-decoration:none;border:1px solid transparent}
.btn-primary{background:#4f46e5;color:#fff}
.btn-secondary{background:#fff;color:#0f172a;border-color:#cbd5e1}
</style>
</head>
<body>
  <div class="wrap">
    <a class="brand" href="/">
      <svg width="34" height="34" viewBox="0 0 64 64" aria-hidden="true"><defs><linearGradient id="wpm" x1="8" y1="4" x2="58" y2="60" gradientUnits="userSpaceOnUse"><stop stop-color="#6366F1"/><stop offset=".55" stop-color="#4F46E5"/><stop offset="1" stop-color="#C026D3"/></linearGradient></defs><rect width="64" height="64" rx="16" fill="url(#wpm)"/><path d="M16 42V24.5L24.2 38h3.1L35.6 24.5V42h4.4V22h-6.2L25.8 35.4 17.8 22H11.5v20H16z" fill="#fff"/><rect x="42" y="28" width="5.2" height="14" rx="1.4" fill="#fff" opacity=".92"/><rect x="48.6" y="22" width="5.2" height="20" rx="1.4" fill="#fff" opacity=".75"/></svg>
      WINDELS PANEL
    </a>
    <div><span class="code">Maintenance</span></div>
    <h1>We&rsquo;ll be back shortly</h1>
    <p>WINDELS PANEL is currently undergoing maintenance. Orders, the wallet and the catalogue are temporarily unavailable. Please check back in a few minutes.</p>
    <div class="actions">
      <a class="btn btn-primary" href="/" onclick="setTimeout(function(){location.reload();},0);return true;">Try again</a>
      <a class="btn btn-secondary" href="/admin/login">Staff sign in</a>
      <a class="btn btn-secondary" href="/contact">Contact support</a>
    </div>
  </div>
</body>
</html>
