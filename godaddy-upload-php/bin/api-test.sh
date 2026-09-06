#!/bin/bash
# End-to-end exercise of the PHP API. Every call goes over HTTP, exactly as
# the browser makes it.
BASE=http://127.0.0.1:8080/api/v1
JAR=$(mktemp)
PASS=0; FAIL=0

# Walk a dotted path into the JSON on stdin. "#" as the last segment
# yields the length instead of the value.
j() { python3 -c '
import sys, json
try:
    d = json.load(sys.stdin)
except Exception:
    sys.exit(0)
for k in sys.argv[1].split("."):
    if k == "":
        continue
    if k == "#":
        print(len(d)); sys.exit(0)
    try:
        d = d[int(k)] if k.lstrip("-").isdigit() else d[k]
    except Exception:
        sys.exit(0)
print(d)
' "$1" 2>/dev/null; }

check() { # name expected actual
  if [ "$2" = "$3" ]; then echo "  ok   $1"; PASS=$((PASS+1));
  else echo "  FAIL $1 — expected [$2] got [$3]"; FAIL=$((FAIL+1)); fi
}

req() { # method path [data] [token]
  local m=$1 p=$2 d=$3 t=$4
  local args=(-s -c "$JAR" -b "$JAR" -X "$m" "$BASE$p" -H 'Content-Type: application/json')
  [ -n "$t" ] && args+=(-H "Authorization: Bearer $t")
  [ -n "$d" ] && args+=(-d "$d")
  curl "${args[@]}"
}

code() { # method path [data] [token]
  local m=$1 p=$2 d=$3 t=$4
  local args=(-s -o /dev/null -w '%{http_code}' -c "$JAR" -b "$JAR" -X "$m" "$BASE$p" -H 'Content-Type: application/json')
  [ -n "$t" ] && args+=(-H "Authorization: Bearer $t")
  [ -n "$d" ] && args+=(-d "$d")
  curl "${args[@]}"
}

# ── Reset to a known state ──
# Counts are asserted exactly ("the feed holds 10"), so the suite has to
# start from the same database every time. Leftovers from a previous run
# make it fail on the second pass with numbers that are one too high, which
# looks like a bug in the app and is not.
#
# The rate limiter is cleared for the same reason: it is real and shared,
# and ~90 requests from one IP per run trips the 300-per-15-minutes ceiling
# on the third pass. It is tested deliberately at the end instead.
# Credentials come from config/.env — the same file the app reads — so this
# script carries no secrets of its own and works on any machine.
APP=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
ENVFILE="$APP/config/.env"
if [ ! -r "$ENVFILE" ]; then
  echo "Cannot read $ENVFILE — copy config/.env.example to config/.env first." >&2
  exit 1
fi
envval() { sed -n "s/^$1=//p" "$ENVFILE" | head -1 | tr -d '"'"'"'\r'; }

DB_NAME=$(envval DB_NAME); DB_USER=$(envval DB_USER)
DB_PASSWORD=$(envval DB_PASSWORD); DB_HOST=$(envval DB_HOST)
PASSKEY=$(envval ADMIN_PASSKEY)

# mariadb, mysql, or Homebrew's keg-only build — whichever is present.
MYSQL=$(command -v mariadb || command -v mysql || echo /opt/homebrew/opt/mariadb/bin/mariadb)

$MYSQL -u "$DB_USER" -p"$DB_PASSWORD" -h "${DB_HOST:-127.0.0.1}" "$DB_NAME" <<'SQL' 2>/dev/null
SET FOREIGN_KEY_CHECKS = 0;
TRUNCATE TABLE audit_logs;
TRUNCATE TABLE enquiries;
TRUNCATE TABLE reviews;
TRUNCATE TABLE favorites;
TRUNCATE TABLE images;
TRUNCATE TABLE properties;
TRUNCATE TABLE users;
TRUNCATE TABLE rate_limits;
SET FOREIGN_KEY_CHECKS = 1;
SQL
php "$APP/bin/seed.php" > /dev/null 2>&1

echo "── Auth ──────────────────────────────────"
EMAIL="owner-$RANDOM@example.com"
REG='{"name":"Test Owner","email":"EMAIL_HERE","password":"correct-horse-battery","accountType":"owner"}'
REG=${REG/EMAIL_HERE/$EMAIL}
R=$(req POST /auth/register "$REG")
TOKEN=$(echo "$R" | j "data.accessToken")
check "register returns a token" "yes" "$([ -n "$TOKEN" ] && echo yes || echo no)"
check "register sets role from accountType" "owner" "$(echo "$R" | j "data.user.role")"
check "register never echoes the password" "no" "$(echo "$R" | grep -q password && echo yes || echo no)"

DUP='{"name":"Dup","email":"EMAIL_HERE","password":"correct-horse-battery"}'
DUP=${DUP/EMAIL_HERE/$EMAIL}
check "duplicate email is 409" "409" "$(code POST /auth/register "$DUP")"
check "short password is 422" "422" "$(code POST /auth/register '{"name":"X","email":"x@y.com","password":"short"}')"
UNK='{"name":"X","email":"unknown-field@example.com","password":"correct-horse-battery","role":"admin"}'
check "unknown field is 422" "422" "$(code POST /auth/register "$UNK")"

GOOD='{"email":"EMAIL_HERE","password":"correct-horse-battery"}'
GOOD=${GOOD/EMAIL_HERE/$EMAIL}
L=$(req POST /auth/login "$GOOD")
TOKEN=$(echo "$L" | j "data.accessToken")
check "login succeeds" "yes" "$([ -n "$TOKEN" ] && echo yes || echo no)"
BAD='{"email":"EMAIL_HERE","password":"wrong-password-here"}'
BAD=${BAD/EMAIL_HERE/$EMAIL}
check "wrong password is 401" "401" "$(code POST /auth/login "$BAD")"

check "/auth/me with token" "200" "$(code GET /auth/me "" "$TOKEN")"
check "/auth/me without token" "401" "$(code GET /auth/me)"
check "/auth/me with a forged token" "401" "$(code GET /auth/me "" "eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiJ4In0.zzz")"

RF=$(req POST /auth/refresh)
check "refresh cookie mints a new token" "yes" "$([ -n "$(echo "$RF" | j "data.accessToken")" ] && echo yes || echo no)"

echo
echo "── Properties ────────────────────────────"
check "public feed is 200" "200" "$(code GET /properties)"
check "feed shows only approved" "10" "$(req GET '/properties?limit=100' | j "meta.total")"
check "city filter" "2" "$(req GET '/properties?city=Mumbai' | j "meta.total")"
check "type filter" "2" "$(req GET '/properties?type=Cold%20Storage' | j "meta.total")"
check "text search" "1" "$(req GET '/properties?q=Bhiwandi' | j "meta.total")"
check "rate range" "5" "$(req GET '/properties?minRate=30&maxRate=60' | j "meta.total")"
check "sort rate-asc puts cheapest first" "16" "$(req GET '/properties?sort=rate-asc&status=approved' | j "data.0.rate")"
check "pagination meta" "5" "$(req GET '/properties?limit=2' | j "meta.pages")"
check "bad sort value is 422" "422" "$(code GET '/properties?sort=DROP')"
check "cards carry no floorPlan" "no" "$(req GET '/properties?limit=1' | grep -q floorPlan && echo yes || echo no)"
check "feed never leaks owner id" "no" "$(req GET '/properties?limit=100' | grep -q '"owner"' && echo yes || echo no)"

PID=$(req GET '/properties?limit=1' | j "data.0.id")
check "detail is 200" "200" "$(code GET "/properties/$PID")"
check "detail carries specs" "yes" "$(req GET "/properties/$PID" | grep -q specs && echo yes || echo no)"
check "bad id is 400" "400" "$(code GET '/properties/not-an-id')"
check "unknown id is 404" "404" "$(code GET '/properties/aaaaaaaaaaaaaaaaaaaaaaaa')"

echo
echo "── Creating a listing ────────────────────"
NEW=$(req POST /properties '{"name":"Test Warehouse Near Nagpur","city":"Nagpur","rate":21,"area":30000,"type":"Warehouse","grade":"Grade B","locality":"Butibori","description":"A test listing created by the API test."}' "$TOKEN")
NEWID=$(echo "$NEW" | j "data.item.id")
check "create returns an id" "yes" "$([ -n "$NEWID" ] && echo yes || echo no)"
check "a non-admin submission starts pending" "pending" "$(echo "$NEW" | j "data.item.status")"
check "a non-admin submission is unverified" "False" "$(echo "$NEW" | j "data.item.isVerified")"
check "create without a token is 401" "401" "$(code POST /properties '{"name":"Nope","city":"X","rate":1,"area":1}')"
check "create with status is 422" "422" "$(code POST /properties '{"name":"Sneaky Listing","city":"X","rate":1,"area":1,"status":"approved"}' "$TOKEN")"
check "create missing rate is 422" "422" "$(code POST /properties '{"name":"No Rate Here","city":"X","area":1}' "$TOKEN")"
check "pending listing hidden from the feed" "10" "$(req GET '/properties?limit=100' | j "meta.total")"
check "owner can see their own pending listing" "200" "$(code GET "/properties/$NEWID" "" "$TOKEN")"
check "a stranger cannot" "404" "$(code GET "/properties/$NEWID")"
check "/properties/mine lists it" "1" "$(req GET /properties/mine "" "$TOKEN" | j "data.items.#")"

echo
echo "── Ownership ─────────────────────────────"
OTHEREMAIL="other-$RANDOM@example.com"
OREG='{"name":"Someone Else","email":"EMAIL_HERE","password":"correct-horse-battery"}'
OREG=${OREG/EMAIL_HERE/$OTHEREMAIL}
OTHER=$(req POST /auth/register "$OREG")
OTHERTOKEN=$(echo "$OTHER" | j "data.accessToken")
check "another user cannot edit it" "403" "$(code PATCH "/properties/$NEWID" '{"rate":1}' "$OTHERTOKEN")"
check "another user cannot delete it" "403" "$(code DELETE "/properties/$NEWID" "" "$OTHERTOKEN")"
check "the owner can edit it" "200" "$(code PATCH "/properties/$NEWID" '{"rate":24}' "$TOKEN")"
check "a non-admin cannot approve" "403" "$(code PATCH "/properties/$NEWID/approve" "" "$TOKEN")"
check "admin routes reject a normal user" "403" "$(code GET /admin/stats "" "$TOKEN")"
check "admin routes reject an anonymous caller" "401" "$(code GET /admin/stats)"

echo
echo "── Admin ─────────────────────────────────"
ADMINBODY='{"passkey":"PASSKEY_HERE"}'
ADMINBODY=${ADMINBODY/PASSKEY_HERE/$PASSKEY}
A=$(req POST /auth/admin "$ADMINBODY")
ADMIN=$(echo "$A" | j "data.accessToken")
check "passkey grants an admin session" "admin" "$(echo "$A" | j "data.user.role")"
check "a wrong passkey is 401" "401" "$(code POST /auth/admin '{"passkey":"not-the-passkey"}')"
check "admin stats" "200" "$(code GET /admin/stats "" "$ADMIN")"
check "stats count pending" "4" "$(req GET /admin/stats "" "$ADMIN" | j "data.pending")"
check "admin sees every status" "15" "$(req GET '/admin/properties?status=all&limit=100' "" "$ADMIN" | j "meta.total")"
check "admin approves" "approved" "$(req PATCH "/properties/$NEWID/approve" "" "$ADMIN" | j "data.item.status")"
check "approved listing joins the feed" "11" "$(req GET '/properties?limit=100' | j "meta.total")"
check "reject needs a reason" "422" "$(code PATCH "/properties/$NEWID/reject" '{}' "$ADMIN")"
check "admin rejects with a reason" "rejected" "$(req PATCH "/properties/$NEWID/reject" '{"reason":"Test rejection reason"}' "$ADMIN" | j "data.item.status")"
check "audit log recorded it" "yes" "$(req GET /admin/audit "" "$ADMIN" | grep -q 'property.rejected' && echo yes || echo no)"
check "admin user list" "200" "$(code GET /admin/users "" "$ADMIN")"

echo
echo "── Favourites, enquiries, reviews ────────"
FAVID=$(req GET '/properties?limit=1' | j "data.0.id")
check "add a favourite" "200" "$(code POST "/favorites/$FAVID" "" "$TOKEN")"
check "adding twice is still 200" "200" "$(code POST "/favorites/$FAVID" "" "$TOKEN")"
check "favourites list has one" "1" "$(req GET /favorites "" "$TOKEN" | j "data.items.#")"
check "remove it" "200" "$(code DELETE "/favorites/$FAVID" "" "$TOKEN")"
check "list is empty again" "0" "$(req GET /favorites "" "$TOKEN" | j "data.items.#")"
check "favourites need a session" "401" "$(code GET /favorites)"

ENQ='{"name":"Jane Visitor","email":"jane@example.com","message":"Is this still available?","property":"PID_HERE"}'
ENQ=${ENQ/PID_HERE/$FAVID}
check "anonymous enquiry is accepted" "201" "$(code POST /enquiries "$ENQ")"
check "enquiry with no message is 422" "422" "$(code POST /enquiries '{"name":"Jane","email":"jane@example.com"}')"
check "enquiry cannot set its own status" "422" "$(code POST /enquiries '{"name":"Jane","email":"j@e.com","message":"Hello there","status":"closed"}')"
check "admin sees the enquiry" "1" "$(req GET /admin/enquiries "" "$ADMIN" | j "meta.total")"

check "review needs a session" "401" "$(code POST "/properties/$FAVID/reviews" '{"rating":5,"comment":"Excellent facility here"}')"
check "post a review" "201" "$(code POST "/properties/$FAVID/reviews" '{"rating":5,"comment":"Excellent facility, very well run."}' "$OTHERTOKEN")"
check "a pending review is not public" "0" "$(req GET "/properties/$FAVID/reviews" | j "data.count")"
check "rating out of range is 422" "422" "$(code POST "/properties/$FAVID/reviews" '{"rating":9,"comment":"Nine out of five stars"}' "$TOKEN")"
RVID=$(req GET /admin/reviews "" "$ADMIN" | j "data.items.0.id")
check "moderation queue has it" "yes" "$([ -n "$RVID" ] && echo yes || echo no)"
check "reject without a reason is 422" "422" "$(code PATCH "/admin/reviews/$RVID" '{"status":"rejected"}' "$ADMIN")"
check "publish it" "200" "$(code PATCH "/admin/reviews/$RVID" '{"status":"published"}' "$ADMIN")"
check "published review is public" "1" "$(req GET "/properties/$FAVID/reviews" | j "data.count")"
check "average is computed" "5" "$(req GET "/properties/$FAVID/reviews" | j "data.average")"

echo
echo "── Contact details ───────────────────────"
check "contact needs a session" "401" "$(code GET "/properties/$FAVID/contact")"
check "signed in gets contact" "200" "$(code GET "/properties/$FAVID/contact" "" "$TOKEN")"
check "the lookup is audited" "yes" "$(req GET /admin/audit "" "$ADMIN" | grep -q contact_viewed && echo yes || echo no)"

echo
echo "── Errors and routing ────────────────────"
check "unknown route is 404" "404" "$(code GET /nope)"
check "wrong method is 405" "405" "$(code DELETE /properties)"
check "malformed JSON is 400" "400" "$(curl -s -o /dev/null -w '%{http_code}' -X POST "$BASE/auth/login" -H 'Content-Type: application/json' -d '{not json')"
check "404 answers in JSON" "yes" "$(curl -s -o /dev/null -w '%{content_type}' "$BASE/nope" | grep -q json && echo yes || echo no)"
check "logout" "200" "$(code POST /auth/logout "" "$TOKEN")"
check "token is dead after logout" "401" "$(code POST /auth/refresh)"

echo
echo "── Uploads ───────────────────────────────"
FIX=$(mktemp -d)
# A real 1600x900 JPEG.
php -r "\$i=imagecreatetruecolor(1600,900);
for(\$x=0;\$x<1600;\$x+=40){imagefilledrectangle(\$i,\$x,0,\$x+39,899,imagecolorallocate(\$i,(\$x/8)%256,120,200-(\$x/10)%200));}
imagejpeg(\$i,'$FIX/photo.jpg',92);"
# Text with a .jpg name.
printf 'not an image at all' > "$FIX/fake.jpg"
# A PHP shell with a .jpg name — the classic upload attack.
printf '<?php system(\$_GET["c"]); ?>' > "$FIX/shell.php.jpg"
# Valid JPEG magic bytes, unreadable body.
php -r "file_put_contents('$FIX/truncated.jpg', \"\\xFF\\xD8\\xFF\\xE0\" . str_repeat(\"\\x00\", 200));"

up() { curl -s -X POST "$BASE/upload" -H "Authorization: Bearer $2" -F "files[]=@$1"; }
upcode() { curl -s -o /dev/null -w '%{http_code}' -X POST "$BASE/upload" -H "Authorization: Bearer $2" -F "files[]=@$1"; }

check "upload needs a session" "401" "$(curl -s -o /dev/null -w '%{http_code}' -X POST "$BASE/upload" -F "files[]=@$FIX/photo.jpg")"

U=$(up "$FIX/photo.jpg" "$TOKEN")
check "a real photo uploads" "True" "$(echo "$U" | j "success")"
check "four renditions are produced" "4" "$(echo "$U" | j "data.files.0.variants.#")"
check "output is WebP, whatever went in" "image/webp" "$(echo "$U" | j "data.files.0.contentType")"
check "a blur placeholder is inlined" "yes" "$(echo "$U" | grep -q 'data:image/webp;base64' && echo yes || echo no)"
check "the WebP set is smaller than the JPEG" "yes" "$([ "$(echo "$U" | j "data.files.0.size")" -lt "$(stat -f%z "$FIX/photo.jpg")" ] && echo yes || echo no)"

IMGID=$(echo "$U" | j "data.files.0.publicId")
IMGURL=$(echo "$U" | j "data.files.0.variants.0.url")
check "the rendition is served" "200" "$(curl -s -o /dev/null -w '%{http_code}' "http://127.0.0.1:8080$IMGURL")"
check "it is served as WebP" "image/webp" "$(curl -s -o /dev/null -w '%{content_type}' "http://127.0.0.1:8080$IMGURL")"
check "/images/:id redirects to the full size" "302" "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/images/$IMGID")"
check "?w= picks the matching rendition" "yes" "$(curl -s -o /dev/null -w '%{redirect_url}' "$BASE/images/$IMGID?w=320" | grep -q 'w320.webp' && echo yes || echo no)"

# The type is sniffed from the leading bytes, never taken from the
# browser's Content-Type — these three are all named .jpg.
check "text renamed .jpg is refused" "422" "$(upcode "$FIX/fake.jpg" "$TOKEN")"
check "a PHP shell renamed .jpg is refused" "422" "$(upcode "$FIX/shell.php.jpg" "$TOKEN")"
check "a truncated JPEG is refused" "422" "$(upcode "$FIX/truncated.jpg" "$TOKEN")"
check "no shell reached the uploads folder" "0" "$(find "$APP/public/uploads" -name '*.php*' 2>/dev/null | wc -l | tr -d ' ')"

check "a stranger cannot delete the image" "403" "$(code DELETE "/images/$IMGID" "" "$OTHERTOKEN")"
check "the uploader can" "200" "$(code DELETE "/images/$IMGID" "" "$TOKEN")"
check "the files are gone with it" "404" "$(curl -s -o /dev/null -w '%{http_code}' "http://127.0.0.1:8080$IMGURL")"
rm -rf "$FIX"

echo
echo "── Rate limiting ─────────────────────────"
# Eleven failed sign-ins against one account. AUTH_RATE_LIMIT_MAX is 10, so
# the eleventh must be refused rather than answered with another 401.
BRUTE='{"email":"bruteforce@example.com","password":"guess-number-N"}'
LAST=""
for i in $(seq 1 12); do
  LAST=$(code POST /auth/login "$BRUTE")
done
check "brute force is throttled" "429" "$LAST"
# A different account is a different bucket, so one attacker cannot lock
# everyone else out by burning a shared counter.
OTHERBRUTE='{"email":"someone-different@example.com","password":"guess-number-N"}'
check "a different account is a separate bucket" "401" "$(code POST /auth/login "$OTHERBRUTE")"

echo
echo "═════════════════════════════════════════"
echo "  passed: $PASS    failed: $FAIL"
rm -f "$JAR"
[ "$FAIL" -eq 0 ]
