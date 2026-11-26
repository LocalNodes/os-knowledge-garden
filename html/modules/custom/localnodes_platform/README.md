# LocalNodes platform

Site-wide configuration for a LocalNodes community site, applied when the
module is enabled on a fresh install.

## Site-wide roles and groups

Site-wide roles do not read a group they are not in. A site manager, a content
manager and a site administrator who are not members of a members-only group
cannot open that group, its members list or its content. In a group that is
listed but closed, they see that the group exists, not what its members post
for the group only. Joining the group gives them what any member has.

The administrator role still runs the site: it creates groups, manages people,
roles and site settings, and enables modules. Inside a group it is not in, it
cannot edit or remove content or manage members; the group's own organizers
do that.

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

Two limits:

- **An administrator can give this access back to themselves.** The role can
  change roles and permissions, so a deliberate change can undo it. It is not
  given by default.
- **The recovery account sees everything.** Account 1, kept blocked for server
  recovery (see `docs/SELF-HOST.md`), keeps Drupal's full access to every
  group while it is unblocked.

This is applied at install time. A site installed before it keeps its old
roles until it is installed again.
