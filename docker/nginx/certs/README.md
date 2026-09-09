# TLS material for the production nginx container

The production compose mounts this directory read-only at `/etc/nginx/certs`
and expects:

    fullchain.pem   certificate chain (leaf + intermediates)
    privkey.pem     private key, 0600, owned by the deploy user

Symlinks are honoured (point them at certbot/acme.sh output). Committed
certificates or keys are stripped by the repository blocklist — never add
real private keys here.
