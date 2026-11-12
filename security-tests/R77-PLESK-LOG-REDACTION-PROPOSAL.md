# R77 - GST Fleetbase Nginx/Plesk query redaction gate (NOT APPLIED)

Read-only audit only. Generated GST Plesk vhosts:
- api-fleet.globalsentransport.fr
- fleet.globalsentransport.fr
- ws-fleet.globalsentransport.fr

Each has active access_log entries with no explicit custom format.
Nginx's default combined format includes the full request, including
query string. No live log content was read. This is evidence of a
query-leak risk, NOT proof that any actual pairing secret already
appears in a log.

New QR V2 transfers only opaque 64-character token inside POST JSON,
and legacy GET link-app is hard-denied. Nevertheless, old URLs and
request/referrer/error logging require explicit protection.

## Candidate log format (proposal ONLY, nginx http context)

    log_format gst_sensitive_free '$remote_addr - - [$time_local] '
        '"$request_method $uri $server_protocol" $status '
        '$body_bytes_sent "-" "-"';

This is an HTTP-context directive, not a Plesk vhost server context
directive. Select the named format in reviewed per-server access_log
directives for SSL, non-SSL, proxy and fallback contexts.
Never log: $request, $request_uri, $args, $query_string,
$http_referer, $request_body, $http_authorization, cookies or
user-controlled headers.

Before any production change: inventory Plesk/nginx/Apache/Docker logs,
check correct Plesk include context and regeneration behavior, test
synthetic canaries on an approved isolated endpoint, preserve retention
and file permissions, validate nginx -t and tested rollback. Never
send tokens to production merely to test the log filter.

No live Plesk edits, reload, deploy, QA/prod HTTP canary in R77.
Privacy gate stays NO-GO.
