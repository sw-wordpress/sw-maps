import { describe, expect, it } from 'vitest';
import {
  EARTH_RADIUS_M,
  boundsOf,
  distanceMeters,
  flattenPositions,
  isValidPosition,
  lineLengthMeters,
  parseAttribution,
  safeColor,
  safeLink,
} from '../src';

describe('isValidPosition', () => {
  it('accepts valid and boundary positions', () => {
    expect(isValidPosition([8.4, 49])).toBe(true);
    expect(isValidPosition([180, 85.0511287798066])).toBe(true);
    expect(isValidPosition([1, 2, 300])).toBe(true);
  });
  it.each([
    ['too short', [1]],
    ['too long', [1, 2, 3, 4]],
    ['string', ['1', '2']],
    ['nan', [NaN, 1]],
    ['infinity', [1, Infinity]],
    ['lon range', [181, 0]],
    ['lat range', [0, 86]],
    ['not array', 'x'],
  ])('rejects %s', (_name, value) => {
    expect(isValidPosition(value)).toBe(false);
  });
});

describe('geometry helpers', () => {
  it('flattens nested coordinates and skips invalid leaves', () => {
    expect(
      flattenPositions([
        [
          [0, 0],
          [1, 1],
        ],
        [
          [200, 0],
          [2, 2],
        ],
      ]),
    ).toEqual([
      [0, 0],
      [1, 1],
      [2, 2],
    ]);
    expect(flattenPositions([5, 6])).toEqual([[5, 6]]);
    expect(flattenPositions('x')).toEqual([]);
  });
  it('computes bounds', () => {
    expect(
      boundsOf([
        [1, 2],
        [5, -3],
        [3, 0],
      ]),
    ).toEqual([1, -3, 5, 2]);
    expect(boundsOf([])).toBeNull();
  });
});

describe('parseAttribution', () => {
  it('parses links and text', () => {
    expect(
      parseAttribution('© [OpenStreetMap](https://www.openstreetmap.org/copyright) contributors'),
    ).toEqual([
      { text: '© ' },
      { text: 'OpenStreetMap', href: 'https://www.openstreetmap.org/copyright' },
      { text: ' contributors' },
    ]);
  });
  it('never creates links for unsafe targets', () => {
    const parts = parseAttribution('[x](javascript:alert(1)) [y](data:text/html,1)');
    expect(parts.every((p) => p.href === undefined)).toBe(true);
  });
  it('keeps plain text and handles empty input', () => {
    expect(parseAttribution('Plain')).toEqual([{ text: 'Plain' }]);
    expect(parseAttribution('')).toEqual([]);
  });
});

describe('safeLink / safeColor', () => {
  it('allows http(s), mailto and tel only', () => {
    expect(safeLink('https://example.org/a')).toBe('https://example.org/a');
    expect(safeLink('mailto:a@example.org')).toBe('mailto:a@example.org');
    expect(safeLink('tel:+491')).toBe('tel:+491');
    for (const bad of [
      'javascript:alert(1)',
      'data:text/html,x',
      '//evil',
      'https://a b',
      null,
      undefined,
    ]) {
      expect(safeLink(bad)).toBeNull();
    }
  });
  it('allows hex colours only', () => {
    expect(safeColor('#aabbcc', '#000')).toBe('#aabbcc');
    expect(safeColor('red; background:url(x)', '#000')).toBe('#000');
    expect(safeColor(5, '#111')).toBe('#111');
  });

  it('measures great-circle distances like the PHP calculator', () => {
    expect(distanceMeters([8, 49], [8, 49])).toBe(0);
    expect(distanceMeters([0, 0], [0, 1])).toBeCloseTo(111195, -2);
    expect(distanceMeters([13.405, 52.52], [2.3522, 48.8566])).toBeCloseTo(878000, -4);
    expect(distanceMeters([0, 60], [1, 60]) / distanceMeters([0, 0], [1, 0])).toBeCloseTo(0.5, 2);
    expect(distanceMeters([0, 0], [180, 0])).toBeCloseTo(Math.PI * EARTH_RADIUS_M, 0);
  });

  it('sums the length of lines and ignores everything else', () => {
    const line = {
      type: 'LineString',
      coordinates: [
        [0, 0],
        [0, 1],
        [0, 2],
      ],
    };
    expect(lineLengthMeters(line)).toBeCloseTo(222390, -3);
    const multi = {
      type: 'MultiLineString',
      coordinates: [
        [
          [0, 0],
          [0, 1],
        ],
        [
          [10, 0],
          [10, 1],
        ],
      ],
    };
    expect(lineLengthMeters(multi)).toBeCloseTo(222390, -3);
    expect(lineLengthMeters({ type: 'Point', coordinates: [1, 1] })).toBe(0);
    expect(
      lineLengthMeters({
        type: 'Polygon',
        coordinates: [
          [
            [0, 0],
            [1, 0],
            [1, 1],
            [0, 0],
          ],
        ],
      }),
    ).toBe(0);
    expect(lineLengthMeters({ type: 'MultiLineString', coordinates: 'x' })).toBe(0);
  });
});
