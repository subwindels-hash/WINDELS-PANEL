<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Page not found · WINDELS PANEL</title>
<style>
body{margin:0;font-family:Inter,system-ui,sans-serif;color:#0f172a;background:#fff;line-height:1.6}
.wrap{max-width:36rem;margin:12vh auto;padding:0 1.25rem;text-align:center}
h1{font-family:Georgia,serif;font-size:2rem}
a{color:#4f46e5}
.btn{display:inline-block;margin:.35rem;padding:.65rem 1rem;border-radius:.75rem;background:#4f46e5;color:#fff;text-decoration:none;font-weight:600}
.btn-secondary{background:#fff;color:#0f172a;border:1px solid #cbd5e1}
</style>
</head>
<body>
  <div class="wrap">
    <p>404</p>
    <h1><?php echo htmlspecialchars(isset($heading) ? $heading : 'Page not found'); ?></h1>
    <p><?php echo isset($message) ? $message : 'That address is not a page on WINDELS PANEL.'; ?></p>
    <p>
      <a class="btn" href="/">Home</a>
      <a class="btn btn-secondary" href="/services">Services</a>
    </p>
  </div>
</body>
</html>
