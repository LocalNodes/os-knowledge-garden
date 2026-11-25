import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const code = readFileSync(new URL('../../js/house-mentions.js', import.meta.url), 'utf8');

function fixture(context, initialInstance) {
  const events = new Map();
  const widgetCalls = [];
  let instance = initialInstance;
  const element = { getAttribute: () => JSON.stringify(context) };
  const wrapped = {
    data: () => instance,
    on: (event, callback) => events.set(event, callback),
    areacomplete: (...args) => widgetCalls.push(args),
  };
  const $ = () => wrapped;
  $.param = (value) => new URLSearchParams(value).toString();
  const Drupal = { behaviors: {}, url: (path) => `/site/${path}` };
  const editorEvents = new Map();
  const once = () => [element];
  vm.runInNewContext(code, {
    jQuery: $, Drupal, once,
    window: { CKEDITOR: { on: (event, callback) => editorEvents.set(event, callback) } },
  });
  Drupal.behaviors.houseMentionContext.attach({});
  return { events, editorEvents, widgetCalls, setInstance: (value) => { instance = value; } };
}

test('textarea picker sends explicit actual page context beneath the site base path', () => {
  const instance = { options: { widget: 'areacomplete' } };
  const { widgetCalls } = fixture({ house_entity_type: 'node', house_entity_id: 42 }, instance);
  assert.equal(instance.options.source, '/site/mentions-autocomplete?house_entity_type=node&house_entity_id=42');
  assert.equal(widgetCalls.length, 1);
  assert.equal(widgetCalls[0][0], 'option');
  assert.equal(widgetCalls[0][1], 'source');
  assert.equal(widgetCalls[0][2], instance.options.source);
});

test('the CKEditor picker receives the same scoped endpoint', () => {
  const instance = { settings: {} };
  fixture({ house_group_id: 7 }, instance);
  assert.equal(instance.settings.source, '/site/mentions-autocomplete?house_group_id=7');
});

test('late AJAX/editor initialization receives context before fetching suggestions', () => {
  const result = fixture({ house_entity_type: 'post', house_entity_id: 19 }, null);
  const instance = { settings: {} };
  result.setInstance(instance);
  result.events.get('focusin.houseMentionContext')();
  assert.equal(instance.settings.source, '/site/mentions-autocomplete?house_entity_type=post&house_entity_id=19');
  instance.settings.source = 'old';
  result.editorEvents.get('instanceReady')();
  assert.equal(instance.settings.source, '/site/mentions-autocomplete?house_entity_type=post&house_entity_id=19');
});
