#!/bin/bash
# Access and error logs without a member's words.
#
# Search and AI overview requests carry a member's words in the query string
# (?keys=, ?q=), Open Social's search pages carry them in the path
# (/search/all/<words>), and a Referer can repeat either. The logs go to the
# container log, which the host keeps and operators can read.
#
# The access log keeps the method, the path without its query string,
# protocol, final status, bytes and user agent, and nothing else from the
# request. A search path keeps its first two parts and loses the words:
# /search/all/<words> and /<language>/search/all/<words> are logged as
# /search/all/- and /<language>/search/all/-. Drupal's watchdog applies the
# same rule (DbLogWithoutQuery in localnodes_platform).
#
# The error log drops the ", referer: <URL>" Apache adds to a request's error
# lines by default.
set -eu

CONF=/etc/apache2/conf-available/localnodes-access-log.conf
cat > "$CONF" <<'EOF'
# The path to log: the request path without its query string, with the words
# after /search/<kind>/ replaced by "-". Matched on the decoded path, without
# regard to case, with repeated slashes and behind /index.php, as Drupal
# routes all of those to the same search page. The raw match covers a path whose decoding fails (an
# encoded slash, as in a search for "a/b"); the decoded one covers encoded
# letters.
# Drupal's rewrite to index.php is an internal redirect, and a plain %{...}e
# reads the final request; %<{...}e reads the request as it came in.
SetEnvIf Request_URI "^(.*)$" LOCALNODES_LOG_PATH=$1
SetEnvIfNoCase Request_URI "^((?:/+index\.php)?(?:/+[a-z]{2,3}(?:-[a-z0-9]+)?)?/+search/+[^/]+)/+[^/]" LOCALNODES_LOG_PATH=$1/-
SetEnvIfExpr "unescape(%{REQUEST_URI}) =~ m#^((?:/+index\.php)?(?:/+[a-z]{2,3}(?:-[a-z0-9]+)?)?/+search/+[^/]+)/+[^/]#i" LOCALNODES_LOG_PATH=$1/-
LogFormat "%h %l %u %t \"%m %<{LOCALNODES_LOG_PATH}e %H\" %>s %O \"%{User-Agent}i\"" localnodes_no_query

# Apache's default error line format, without the Referer it appends.
ErrorLogFormat "[%{u}t] [%-m:%l] [pid %P] %7F: %E: [client\ %a] %M"
EOF
a2enconf localnodes-access-log

# Name the format explicitly on every CustomLog in the shipped sites.
sed -ri 's!^(\s*CustomLog\s+\S+)\s+\S+\s*$!\1 localnodes_no_query!' \
  /etc/apache2/sites-available/000-default.conf \
  /etc/apache2/sites-available/default-ssl.conf

# The catch-all log for other virtual hosts uses vhost_combined (with %r and
# Referer); there are no other virtual hosts, so switch it off.
a2disconf other-vhosts-access-log

# Fail the build if any enabled config still logs with a format other than ours.
if grep -rhE '^\s*CustomLog' /etc/apache2/sites-enabled/ /etc/apache2/conf-enabled/ \
  | grep -vq 'localnodes_no_query'; then
  echo "apache-access-log.sh: a CustomLog without localnodes_no_query is still enabled" >&2
  exit 1
fi
