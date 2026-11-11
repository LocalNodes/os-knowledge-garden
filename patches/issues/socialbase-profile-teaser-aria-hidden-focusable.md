Status: draft, not posted; waits for Tom (D-09)

# socialbase: profile teaser puts two focusable links inside aria-hidden

- Project: Open Social (goalgorilla/open_social), theme socialbase
- Version seen: Open Social 13.1.0
- Component: `themes/socialbase/templates/profile/profile--profile--teaser.html.twig`
- Category: bug, accessibility (WCAG 2.2, 4.1.2 Name, Role, Value; axe rule `aria-hidden-focus`)
- Our workaround: a module copy of the template with the fix below (`localnodes_platform/templates/profile/profile--profile--teaser--localnodes.html.twig`, plus a `--sky` copy of socialblue's sky teaser that extends it), rendered through the suggestion `profile__profile__teaser__localnodes` that `localnodes_platform_theme_suggestions_profile_alter()` appends for a signed-in viewer. A theme registry alter cannot do it: the bootstrap base theme's registry alter runs after every module's and points each template its themes ship back at the theme. No patch file, because the override lives in our code. It can go once Open Social ships the fix.

## Problem

The profile teaser has three links to the member's profile, all with the same `href="{{ profile_home }}"`. Two of them are hidden from assistive technology but stay in the Tab order:

1. **The avatar link** (block `card_image`): `<div class="teaser__image" aria-hidden="true"><a href="{{ profile_home }}">…image…</a></div>`. The wrapper is hidden; the link inside it is focusable.
2. **The "Profile: <name>" icon link** (block `card_teaser_type`): `<a href="{{ profile_home }}" aria-hidden="true" title="Profile: <name>">…icon…</a>`. The link carries `aria-hidden` itself and is still focusable.

The third link, the member name in `<h4 class="teaser__title">`, is the real one: visible text, not hidden.

A keyboard user tabs onto the first two links, but assistive technology has been told to ignore them, so a screen-reader user hears nothing, or an unlabelled stop, when focus lands there. In socialblue's sky style (whose `profile--profile--teaser--sky.html.twig` extends this template and inherits both blocks), the icon link's box is also off screen or covered, so a sighted keyboard user loses the focus indicator too.

## Steps to reproduce

1. Install Open Social 13.1 with a few member profiles and content (for example the demo content), socialblue as the theme.
2. Search for a term that returns member profiles (for example `/search/all/<term>`).
3. Tab through the results, or run axe on the page.
4. Focus lands on the avatar link and on the "Profile: <name>" link, both inside `aria-hidden`, and axe reports `aria-hidden-focus` on each.

## Proposed resolution

Add `tabindex="-1"` to the two hidden links. The title link already goes to the same profile with the member's name, so the hidden links are redundant for keyboard and screen-reader users. Taking them out of the Tab order (rather than removing `aria-hidden`, which would add two duplicate stops) keeps them as mouse targets and keeps the markup the stock CSS selects on (`.teaser__image + a .teaser__teaser-type`, `.teaser--tile .teaser__image a`):

```diff
   {% block card_image %}
     <div class="teaser__image" aria-hidden="true">
-      <a href="{{ profile_home }}">
+      <a href="{{ profile_home }}" tabindex="-1">
         {{ content.field_profile_image }}
       </a>
     </div>
   {% endblock %}

   {% block card_teaser_type %}
-    <a href="{{ profile_home }}" aria-hidden="true" title="…">
+    <a href="{{ profile_home }}" aria-hidden="true" tabindex="-1" title="…">
```

With this, nothing at or under an `aria-hidden="true"` element in the teaser is keyboard-focusable, and the name link is the teaser's one link stop.

## Related

The same pattern may be in `profile--profile--hero.html.twig` (socialbase) and `profile--profile--hero--sky.html.twig` (socialblue), which also use `aria-hidden`. We have not checked them; they are not on the pages our tests cover. socialblue's stream teaser image link (`.teaser__image > a[aria-hidden="true"]`) is a separate case of the same bug.

## Remaining tasks

- Review and commit.

## User interface changes

None visible. The two hidden links are no longer keyboard stops.
