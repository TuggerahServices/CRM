<?php
// Microsoft Entra configuration (include-only; nginx blocks direct access).
//
// Used for SSO sign-in (OIDC auth-code + PKCE, delegated permissions) and, via
// graph.php, app-only Graph calls with a certificate credential.
//
// The private key (cert_key) never leaves this server. Only the matching PUBLIC
// cert (entra-signing.crt) is uploaded into the Entra app registration.
//
// ============================================================================
// SETUP TODO (Broc) - this app needs its OWN Entra app registration. Do NOT
// reuse the Discounts one: a shared identity cannot be revoked independently,
// and this app handles children's PII with a different audience (enrolments
// team, not PAMs).
//
//   1. DONE. Azure portal -> Entra ID -> App registrations -> New registration
//      Name: "CRM" (created as "Little Miracles Attendances"; renamed by Broc, Nov 2026)
//      Supported account types: single tenant
//      Redirect URI (Web): https://crm.tuggerahservices.com.au/auth/callback
//      (No port - Cloudflare fronts 443 and the origin listens on 53433. No
//      .php either - nginx strips the extension, and Entra matches the
//      redirect_uri as an exact string on BOTH the authorize and token legs.)
//   2. DONE. Application (client) ID copied into 'client_id' below.
//   3. DONE. Signing cert generated on THIS server (24 Jul 2026, RSA-4096,
//      expires 1 Jun 2059) and uploaded under Certificates & secrets ->
//      Certificates. To replace it one day:
//        cd /etc/crm
//        umask 077; openssl req -x509 -newkey rsa:4096 -sha256 -nodes -days 12000 \
//          -keyout entra-signing.key -out entra-signing.crt \
//          -subj "/CN=crm.tuggerahservices.com.au/O=Little Miracles/C=AU"
//        chown root:crm entra-signing.*; chmod 640 entra-signing.key
//      Owned by root, readable by the crm group: the site's PHP pool runs as
//      the crm user and must read the key but never change it.
//      Moved here from _incl/entra/ on 11 Nov 2026, when the project went into git.
//      Upload the .crt ONLY. The .key never leaves this server.
//   4. API permissions: Microsoft Graph -> Delegated -> openid, profile, email,
//      User.Read, for sign-in. Plus Application -> User.Read.All with admin
//      consent, which is granted and IS USED - the User access screen resolves
//      a typed email to a staff member through it (graph_find_user()).
//
//      IT WAS NEARLY WRITTEN OFF. This line said "NO LONGER USED" from the day
//      the groups-overage fallback was removed, which is true of the fallback
//      and was read as true of the permission. MEASURED 20 Aug 2026 instead of
//      assumed: the app-only token comes back carrying
//      roles ["User.Read.All","GroupMember.Read.All"], GET /users/<upn> answers
//      200 for a real account and 404 for one that does not exist, and nothing
//      had to be changed in Azure to get it. Re-measure the same way (dump the
//      `roles` claim of entra_app_token()) before writing that it is gone.
//
//      Domain.Read.All is NOT granted - /domains answers 403 - and is not
//      wanted: USER_EMAIL_DOMAIN in users.php is one constant, and asking for
//      another tenant permission to read a value that changes when the company
//      is renamed is not a trade worth making.
//   5. DROPPED 19 Aug 2026. Was: add a groups claim, so the token carried the
//      Object IDs the allowlist matched. Nothing reads the groups claim now.
//   6. Set 'redirect_uri' below to the SAME URI registered in step 1.
//   7. DROPPED 19 Aug 2026. Was: add the enrolments team's group Object ID.
//      Access is granted per person in the app's own User access screen; there
//      is no group door any more. See the access-control note below.
// ============================================================================

return [
    // --- Tenant / app identity (not secret) ---
    // Tenant is the shared Little Miracles tenant (same as Discounts).
    'tenant_id' => 'fdc60764-ea4e-4eb6-a6e6-6252449ae14b',
    // "CRM" app registration, formerly "Little Miracles Attendances" (Object ID
    // 63a7709d-30b3-4eaa-8860-4b958fd98083, not used by the code - Graph and
    // the token endpoint both address the app by its client ID).
    'client_id' => 'e43ea10a-cadf-4b16-951d-dbe5fd532883',

    // --- Certificate credential (app-only / client_credentials via signed JWT) ---
    // Outside the site folder: never in git, and a branch download from the
    // Magic panel (git clean -fdx) cannot delete them. See "Secrets" in CLAUDE.md.
    'cert_key'  => '/etc/crm/entra-signing.key',   // private key (signs the client assertion)
    'cert_pub'  => '/etc/crm/entra-signing.crt',   // public cert (thumbprint -> x5t header)

    // --- OIDC / SSO ---
    // Must match the URI registered in Entra exactly (step 6).
    'redirect_uri' => 'https://crm.tuggerahservices.com.au/auth/callback',
    'authority'    => 'https://login.microsoftonline.com/fdc60764-ea4e-4eb6-a6e6-6252449ae14b',
    'graph_base'   => 'https://graph.microsoft.com/v1.0',

    // --- Access control: MOVED OUT OF THIS FILE, 19 Aug 2026 ---
    //
    // 'allowed_user_emails' and 'allowed_group_ids' used to live here and were
    // read by auth_is_allowed(). Both are gone. Who may sign in, what role they
    // hold and which centres a supervisor sees are rows in `app_users` and
    // `app_user_centres`, edited by an administrator from the User access screen
    // in the app itself, with every change recorded by audit().
    //
    // WHY THE GROUP OPTION WENT TOO, rather than being kept as a second door:
    // a group grants access with no role and no centre attached, and every user
    // now has to resolve to both. It also puts the decision in Azure, with
    // whoever administers the tenant - who is not the person who knows which
    // staff should be reading children's personal data. See users.php and the
    // long note on auth_is_allowed().
    //
    // The Graph permissions in step 4 are no longer needed for SIGN-IN (the
    // groups-overage fallback went with the group check), but they are needed
    // for the User access screen: an email typed there is resolved against the
    // directory before a row is written, so the list shows a person and a typo
    // is refused on the spot. See graph_find_user() and step 4 above.
    //
    // BREAK GLASS, so that a bad edit in that screen cannot lock everybody out
    // of an app with no console and no version control, is USER_BREAK_GLASS in
    // _incl/users.php - an app authorisation decision, not a tenant one.

    // --- Outbound mail (graph_send_mail()) ---
    // Used by the Monday birthday email (birthdays.php) since 2 Oct 2026; the
    // offer emails are still deferred. Needs the Graph APPLICATION permission
    // Mail.Send with admin consent - added 2 Oct 2026, consented 6 Oct 2026.
    // That permission lets this app send as ANY mailbox in the tenant unless an
    // Exchange application access policy narrows it to this one.
    'mail_from' => 'noreply@littlemiracles.com.au',
];
