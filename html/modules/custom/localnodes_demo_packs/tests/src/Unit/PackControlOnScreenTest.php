<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_demo_packs\Unit;

use Drupal\Tests\localnodes_demo_packs\PackChecks;
use Drupal\Tests\UnitTestCase;

/**
 * A screen row finds a control of the kind it names, and nothing else.
 *
 * The functional test hands the page it loaded to PackChecks::controlOnScreen().
 * Here the page is made up, so each way a row could pass for the wrong reason
 * is shown a page that has only that wrong match, and must fail.
 *
 * @group localnodes_demo_packs
 */
final class PackControlOnScreenTest extends UnitTestCase {

  /**
   * Each kind finds its own element.
   */
  public function testEachKindFindsItsControl(): void {
    $cases = [
      'link' => '<a href="/x">Join</a>',
      'tab' => '<nav id="block-local-tasks"><ul><li><a href="/x">Join</a></li></ul></nav>',
      'button' => '<button type="button">Join</button>',
      'submit' => '<button type="submit">Join</button>',
      'field' => '<label for="a">Join</label><input id="a">',
      'option' => '<select><option>Join</option></select>',
      'heading' => '<h2>Join</h2>',
      'placeholder' => '<textarea placeholder="Join"></textarea>',
    ];
    foreach ($cases as $kind => $html) {
      $this->assertTrue(PackChecks::controlOnScreen("<html><body>$html</body></html>", 'Join', $kind), "A $kind is found as a $kind.");
    }
    $this->assertTrue(PackChecks::controlOnScreen('<body><button>Join</button></body>', 'Join', 'submit'), 'A button with no type submits.');
    $this->assertTrue(PackChecks::controlOnScreen('<body><input type="submit" value="Join"></body>', 'Join', 'submit'), 'A submit input counts by its value.');
    $this->assertTrue(PackChecks::controlOnScreen('<body><legend>Join</legend></body>', 'Join', 'field'), 'A legend names a field set.');
    $this->assertTrue(PackChecks::controlOnScreen("<body><a href=\"/x\">What's  up</a></body>", "What's up", 'link'), 'A quote and a run of spaces are handled.');
  }

  /**
   * A control of another kind does not satisfy the row.
   */
  public function testAnotherKindDoesNotCount(): void {
    $cases = [
      'a heading, for a link row' => ['<h2>Join</h2>', 'link'],
      'a heading, for a submit row' => ['<h1>Join</h1>', 'submit'],
      'a link, for a heading row' => ['<a href="/x">Join</a>', 'heading'],
      'a link, for a submit row' => ['<a href="/x">Join</a>', 'submit'],
      'a submit, for a button row' => ['<button type="submit">Join</button>', 'button'],
      'a button, for a submit row' => ['<button type="button">Join</button>', 'submit'],
      'a plain link, for a tab row' => ['<div><a href="/x">Join</a></div>', 'tab'],
      'an anchor with no href, for a link row' => ['<a>Join</a>', 'link'],
      'a label, for a link row' => ['<label>Join</label>', 'link'],
      'a placeholder, for a field row' => ['<input placeholder="Join">', 'field'],
      'a paragraph, for any row' => ['<p>Join</p>', 'link'],
    ];
    foreach ($cases as $name => [$html, $kind]) {
      $this->assertFalse(PackChecks::controlOnScreen("<body>$html</body>", 'Join', $kind), "$name: not a match.");
    }
  }

  /**
   * A longer name does not stand in for the name.
   */
  public function testALongerNameDoesNotCount(): void {
    $this->assertFalse(PackChecks::controlOnScreen('<body><button type="button">Joined</button></body>', 'Join', 'button'));
    $this->assertFalse(PackChecks::controlOnScreen('<body><a href="/x">Join us</a></body>', 'Join', 'link'));
    $this->assertTrue(PackChecks::controlOnScreen('<body><button type="button">Joined</button></body>', 'Joined', 'button'));
  }

  /**
   * A heading or a hidden element with the name, and no real control, fails.
   *
   * The same page with the real control added passes, so the failure is the
   * missing control and nothing else.
   */
  public function testHiddenCopiesDoNotCount(): void {
    $hidden = [
      'hidden attribute' => '<a href="/x" hidden>Join</a>',
      'hidden ancestor' => '<div hidden><a href="/x">Join</a></div>',
      'aria-hidden ancestor' => '<div aria-hidden="true"><a href="/x">Join</a></div>',
      'visually-hidden class' => '<span class="x visually-hidden"><a href="/x">Join</a></span>',
      'hidden class' => '<div class="hidden"><a href="/x">Join</a></div>',
      'inline display none' => '<div style="color: red; display : none"><a href="/x">Join</a></div>', // raw-color-allow: test input, a style attribute that hides the control
      'toolbar id' => '<div id="toolbar-administration"><a href="/x">Join</a></div>',
      'toolbar class' => '<div class="a toolbar-tray"><a href="/x">Join</a></div>',
      'toolbar bar class' => '<nav class="toolbar-bar"><a href="/x">Join</a></nav>',
      'secondary toolbar class' => '<div class="gin-secondary-toolbar"><a href="/x">Join</a></div>',
    ];
    foreach ($hidden as $name => $html) {
      $this->assertFalse(PackChecks::controlOnScreen("<body>$html</body>", 'Join', 'link'), "$name: not a match.");
      $this->assertTrue(PackChecks::controlOnScreen("<body>$html<a href=\"/y\">Join</a></body>", 'Join', 'link'), "$name: the real control is still found.");
    }
    $this->assertFalse(PackChecks::controlOnScreen('<body><div hidden><h2>Join</h2></div></body>', 'Join', 'heading'), 'A hidden heading does not count for a heading row.');
  }

  /**
   * A disabled button or input does not count.
   */
  public function testDisabledControlsDoNotCount(): void {
    $this->assertFalse(PackChecks::controlOnScreen('<body><button type="submit" disabled>Save</button></body>', 'Save', 'submit'));
    $this->assertFalse(PackChecks::controlOnScreen('<body><input type="submit" value="Save" disabled="disabled"></body>', 'Save', 'submit'));
    $this->assertFalse(PackChecks::controlOnScreen('<body><button type="button" disabled>Save</button></body>', 'Save', 'button'));
    $this->assertFalse(PackChecks::controlOnScreen('<body><fieldset disabled><button type="submit">Save</button></fieldset></body>', 'Save', 'submit'));
    $this->assertTrue(PackChecks::controlOnScreen('<body><button type="submit" disabled>Save</button><button type="submit">Save</button></body>', 'Save', 'submit'));
  }

  /**
   * A label shown only by an unavailable or wrong-kind node fails.
   *
   * Each case is a page whose only match is the bad one, with the kind the
   * row names. The same page with a real, enabled control added passes, so
   * the failure is the bad node and nothing else.
   */
  public function testUnavailableOrWrongKindNodesDoNotCount(): void {
    $real = [
      'placeholder' => '<input placeholder="Join">',
      'option' => '<select><option>Join</option></select>',
      'submit' => '<button type="submit">Join</button>',
      'link' => '<a href="/y">Join</a>',
      'field' => '<label for="real">Join</label><input id="real">',
    ];
    $cases = [
      'a placeholder on a div' => ['<div placeholder="Join">x</div>', 'placeholder'],
      'a placeholder on a paragraph' => ['<p placeholder="Join">x</p>', 'placeholder'],
      'a placeholder on a link' => ['<a href="/x" placeholder="Join">x</a>', 'placeholder'],
      'an option in a disabled select' => ['<select disabled><option>Join</option></select>', 'option'],
      'an option in a disabled optgroup' => ['<select><optgroup label="g" disabled><option>Join</option></optgroup></select>', 'option'],
      'a disabled option' => ['<select><option disabled>Join</option></select>', 'option'],
      'a button under aria-disabled' => ['<div aria-disabled="true"><button type="submit">Join</button></div>', 'submit'],
      'a link under aria-disabled' => ['<ul aria-disabled="true"><li><a href="/x">Join</a></li></ul>', 'link'],
      'a link that is aria-disabled' => ['<a href="/x" aria-disabled="true">Join</a>', 'link'],
      'a link under visibility hidden' => ['<div style="visibility:hidden"><a href="/x">Join</a></div>', 'link'],
      'a link that is visibility hidden' => ['<a href="/x" style="color:red; visibility : hidden">Join</a>', 'link'], // raw-color-allow: test input, a style attribute that hides the control
    ];
    foreach ($cases as $name => [$html, $kind]) {
      $this->assertFalse(PackChecks::controlOnScreen("<body>$html</body>", 'Join', $kind), "$name: not a match.");
      $this->assertTrue(PackChecks::controlOnScreen("<body>$html{$real[$kind]}</body>", 'Join', $kind), "$name: the real control is still found.");
    }
  }

  /**
   * A field label counts only when its control exists and is available.
   *
   * The control is the one the label's `for` names by id, or one nested in
   * the label.
   */
  public function testAFieldLabelNeedsItsControl(): void {
    $real = '<label for="real">Join</label><input id="real">';
    $cases = [
      'a label for an id that is not there' => '<label for="gone">Join</label><input id="other">',
      'a label with no for and no control inside' => '<label>Join</label><input id="a">',
      'a label for a disabled input' => '<label for="a">Join</label><input id="a" disabled>',
      'a label for a disabled select' => '<label for="a">Join</label><select id="a" disabled><option>x</option></select>',
      'a label for an aria-disabled input' => '<label for="a">Join</label><input id="a" aria-disabled="true">',
      'a label for an input in a disabled fieldset' => '<label for="a">Join</label><fieldset disabled><input id="a"></fieldset>',
      'a label for a hidden input' => '<label for="a">Join</label><input id="a" type="hidden">',
      'a label for an input in a hidden block' => '<label for="a">Join</label><div hidden><input id="a"></div>',
      'a label for an input under visibility hidden' => '<label for="a">Join</label><div style="visibility:hidden"><input id="a"></div>',
      'a label for a paragraph' => '<label for="a">Join</label><p id="a">x</p>',
      'a label with a disabled input inside' => '<label>Join <input disabled></label>',
    ];
    foreach ($cases as $name => $html) {
      $this->assertFalse(PackChecks::controlOnScreen("<body>$html</body>", 'Join', 'field'), "$name: not a match.");
      $this->assertTrue(PackChecks::controlOnScreen("<body>$html$real</body>", 'Join', 'field'), "$name: the real field is still found.");
    }
    $this->assertTrue(PackChecks::controlOnScreen('<body><label for="a">Join</label><input id="a"></body>', 'Join', 'field'), 'A label for an enabled input counts.');
    $this->assertTrue(PackChecks::controlOnScreen('<body><label>Join <input></label></body>', 'Join', 'field'), 'A label with an input inside counts.');
    $this->assertTrue(PackChecks::controlOnScreen('<body><label for="a">Join</label><select id="a"><option>x</option></select></body>', 'Join', 'field'), 'A label for a select counts.');
    $this->assertTrue(PackChecks::controlOnScreen('<body><label for="a">Join</label><textarea id="a"></textarea></body>', 'Join', 'field'), 'A label for a text area counts.');
    $this->assertTrue(PackChecks::controlOnScreen('<body><fieldset><legend>Join</legend><input></fieldset></body>', 'Join', 'field'), 'A legend of an enabled fieldset counts.');
    $this->assertFalse(PackChecks::controlOnScreen('<body><fieldset disabled><legend>Join</legend><input></fieldset></body>', 'Join', 'field'), 'A legend of a disabled fieldset does not count.');
  }

  /**
   * A required marker after a field name is not part of the name a member reads.
   */
  public function testARequiredMarkerIsNotPartOfTheName(): void {
    $this->assertTrue(PackChecks::controlOnScreen('<body><fieldset><legend><span>Join</span><span class="form-required">*</span></legend><input></fieldset></body>', 'Join', 'field'), 'A legend with a required marker counts.');
    $this->assertTrue(PackChecks::controlOnScreen('<body><label for="a">Join <span class="form-required">*</span></label><input id="a"></body>', 'Join', 'field'), 'A label with a required marker counts.');
    $this->assertFalse(PackChecks::controlOnScreen('<body><fieldset><legend>Join now<span class="form-required">*</span></legend><input></fieldset></body>', 'Join', 'field'), 'A longer name still does not count.');
    $this->assertFalse(PackChecks::controlOnScreen('<body><a href="/x">Join <span class="form-required">*</span></a></body>', 'Join', 'link'), 'The marker is dropped for field names only.');
  }

  /**
   * A row with a region counts only the matches inside that region.
   *
   * A same-named control elsewhere on the page (a teaser in a sidebar) does
   * not stand in for the one the row means. A row with no region reads the
   * whole page.
   */
  public function testAMatchOutsideTheRegionDoesNotCount(): void {
    $aside = '<aside class="teaser"><a href="/other">Join</a></aside>';
    $main = '<main><a href="/mine">Join</a></main>';
    $this->assertTrue(PackChecks::controlOnScreen("<body>$aside</body>", 'Join', 'link'), 'With no region the whole page is read.');
    $this->assertFalse(PackChecks::controlOnScreen("<body>$aside<main><p>x</p></main></body>", 'Join', 'link', 'main'), 'A control only in a sidebar fails a row for the main content.');
    $this->assertTrue(PackChecks::controlOnScreen("<body>$aside$main</body>", 'Join', 'link', 'main'), 'The control in the region is found.');
    $this->assertTrue(PackChecks::controlOnScreen("<body>$aside<div><section id=\"form\"><div><a href=\"/mine\">Join</a></div></section></div></body>", 'Join', 'link', '#form'), 'A region by id holds a control deep inside.');
    $this->assertFalse(PackChecks::controlOnScreen("<body>$aside$main</body>", 'Join', 'link', '#nowhere'), 'A region that is not on the page fails.');
    $this->assertFalse(PackChecks::controlOnScreen('<body><main><div hidden><a href="/x">Join</a></div></main></body>', 'Join', 'link', 'main'), 'A hidden control in the region still fails.');
    $this->assertTrue(PackChecks::controlOnScreen('<body><div class="a"><button type="submit">Join</button></div><div class="b"><button type="submit">Join</button></div></body>', 'Join', 'submit', '.b'), 'A class region works.');
    $this->assertTrue(PackChecks::controlOnScreen('<body><form id="f"><label for="a">Join</label><input id="a"></form></body>', 'Join', 'field', 'form#f'), 'A field label in a form region counts.');
    $this->assertFalse(PackChecks::controlOnScreen('<body><div id="x"><label for="a">Join</label></div><form id="f"><input id="a"></form></body>', 'Join', 'field', 'form#f'), 'A label outside the region does not count.');
  }

  /**
   * A kind the check does not know fails the row instead of passing it.
   */
  public function testUnknownKindFails(): void {
    $this->expectException(\InvalidArgumentException::class);
    PackChecks::controlOnScreen('<body><a href="/x">Join</a></body>', 'Join', 'anything');
  }

}
