#!/usr/bin/env python3
"""Reduce geoBoundaries gbOpen BGD ADM2 to the corridor's three districts for the Cyber corridor map.

Usage: python3 -I scripts/corridor/simplify-boundaries.py <geoBoundaries-BGD-ADM2.geojson> <out.json>
Source: geoBoundaries gbOpen (Bangladesh Bureau of Statistics / OCHA ROAP), CC BY 3.0 IGO.
Douglas-Peucker at 40 m; coordinates rounded to 5 decimals (about 1 m).
"""
import json, math, sys

KEEP = ('Gazipur', 'Dhaka', 'Narayanganj')
TOLERANCE_M = 40.0


def to_xy(p, lat0):
    return (p[0] * 111320 * math.cos(math.radians(lat0)), p[1] * 110540)


def seg_dist(p, a, b):
    dx, dy = b[0] - a[0], b[1] - a[1]
    l2 = dx * dx + dy * dy
    t = 0 if l2 == 0 else max(0, min(1, ((p[0] - a[0]) * dx + (p[1] - a[1]) * dy) / l2))
    return math.hypot(p[0] - (a[0] + t * dx), p[1] - (a[1] + t * dy))


def rdp(points, tol):
    xy = [to_xy(p, 23.8) for p in points]
    keep = {0, len(points) - 1}
    stack = [(0, len(points) - 1)]
    while stack:
        lo, hi = stack.pop()
        far, far_d = -1, tol
        for i in range(lo + 1, hi):
            d = seg_dist(xy[i], xy[lo], xy[hi])
            if d > far_d:
                far, far_d = i, d
        if far > 0:
            keep.add(far)
            stack += [(lo, far), (far, hi)]
    return [points[i] for i in sorted(keep)]


def main(src, out):
    data = json.load(open(src))
    features = []
    for f in data['features']:
        name = f['properties']['shapeName']
        if name not in KEEP:
            continue
        g = f['geometry']
        polys = g['coordinates'] if g['type'] == 'MultiPolygon' else [g['coordinates']]
        rings = []
        for poly in polys:
            ring = rdp(poly[0], TOLERANCE_M)  # outer ring only: holes are not drawn
            if len(ring) >= 4:
                rings.append([[round(x, 5), round(y, 5)] for x, y in ring])
        features.append({'name': name, 'shapeID': f['properties']['shapeID'], 'rings': rings})
    features.sort(key=lambda f: KEEP.index(f['name']))
    json.dump({
        'source': 'geoBoundaries gbOpen BGD ADM2 (Bangladesh Bureau of Statistics, OCHA ROAP)',
        'release': '2023-12-12 (buildDate), data updated 2023-01-19',
        'url': 'https://www.geoboundaries.org/countries.html?country=BGD',
        'license': 'CC BY 3.0 IGO',
        'attribution': 'District boundaries: geoBoundaries (gbOpen), BBS / OCHA ROAP, CC BY 3.0 IGO. Simplified to 40 m.',
        'simplified_tolerance_m': TOLERANCE_M,
        'districts': features,
    }, open(out, 'w'), separators=(',', ':'))


main(sys.argv[1], sys.argv[2])
