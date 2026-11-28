# LocalNodes platform

Site-wide configuration for a LocalNodes community site, applied when the
module is enabled on a fresh install.

## Site-wide roles and groups

Site-wide roles do not read a group they are not in. A site manager, a content
manager and a site administrator who are not members of a members-only group
cannot open that group, its members list or its content. In a group that is
listed but closed, they see that the group exists, not what its members post
for the group only. Joining the group gives them what any member has.

The administrator role still runs the site day to day (see "The
administrator role" below). Inside a group it is not in, it cannot edit or
remove content or manage members; the group's own organizers do that.

For the same reason the administrator role no longer has:

- the administration lists of posts, replies, notices, stored emails and
  files, and the group overview;
- the people export;
- poll administration (adding a poll outside a group, removing someone's
  answer); polls inside a group are for its members;
- every notice's page, and other people's account and group fund lists.

Account 1 can still do all of these. The people list's group filter offers
only groups the viewer can open, and the site log records that a page or reply
was saved or removed, not its title.

One limit:

- **The recovery account sees everything.** Account 1, kept blocked for server
  recovery (see `docs/SELF-HOST.md`), keeps Drupal's full access to every
  group while it is unblocked.

This is applied at install time. A site installed before it keeps its old
roles until it is installed again.

## The administrator role

The administrator role holds a fixed list of permissions, named in
`src/Account/AdministratorPermissions.php`, and nothing else. A permission
that a later module brings is not given until someone puts it on the list.

The administrator can:

- change the site's settings, themes, menus and blocks;
- manage people: add a person, block, unblock and cancel an account, and give
  the roles below its own (verified, content manager, site manager) on the
  person's account form;
- read the site's logs and status report;
- moderate content outside members-only groups.

Account 1 can do everything, including what the administrator cannot:
changing roles and permissions, turning modules on or off, changing another
person's password or email, setting roles from the people list, changing
the mention settings, and giving the administrator role. Members change
their own password by the emailed reset link.

A site adds a permission to the role on purpose in one of two ways:

- **For every site:** put it on the allowed list in
  `AdministratorPermissions.php`. New sites get it on a fresh install.
- **For one site:** account 1 adds it to the setting
  `localnodes_platform.settings:administrator_permissions_added`. The role
  picks it up the next time a module is turned on, or on a fresh install.

A withheld permission can never be added through the setting.
