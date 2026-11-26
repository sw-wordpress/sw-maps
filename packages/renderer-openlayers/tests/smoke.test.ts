import { describe, expect, it } from 'vitest';
import { OpenLayersRenderer } from '../src';
import { makeData } from './fixtures';

describe('smoke', () => {
  it('mounts a real OpenLayers map in jsdom', () => {
    const target = document.createElement('div');
    document.body.append(target);
    const renderer = new OpenLayersRenderer();
    renderer.mount(target, { data: makeData() });
    expect(target.querySelector('.swmap__canvas')).not.toBeNull();
    expect(renderer.getView().zoom).toBeGreaterThan(0);
    renderer.destroy();
  });
});
