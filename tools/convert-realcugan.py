"""Convert a Real-CUGAN ncnn model (realcugan-ncnn-vulkan's models-se, e.g.
up3x-conservative.param/.bin) to the plugin's browser format: model.json (the
layer graph) and weights.bin (float32, in TensorFlow.js layouts).

    python3 tools/convert-realcugan.py models-se/up3x-conservative assets/models/realcugan-up3x-conservative

Only numpy is needed. The graph is the one pnnx exported for Real-CUGAN:
Convolution / Deconvolution (LeakyReLU 0.1), Split, global average Pooling,
InnerProduct (the squeeze-excitation blocks), BinaryOp and Crop.
"""
import json
import os
import struct
import sys

import numpy as np

FP16_TAG = 0x01306B47


def parse_param(path):
    lines = [line for line in open(path, encoding='ascii').read().split('\n') if line.strip()]
    if lines[0].strip() != '7767517':
        raise ValueError('not an ncnn param file')
    layers = []
    for line in lines[2:]:
        parts = line.split()
        nin, nout = int(parts[2]), int(parts[3])
        params = {}
        for item in parts[4 + nin + nout:]:
            key, value = item.split('=')
            key = int(key)
            if key <= -23300:  # array parameter: count, values...
                params[-key - 23300] = [float(v) for v in value.split(',')[1:]]
            else:
                params[key] = float(value)
        layers.append({
            'type': parts[0], 'name': parts[1],
            'ins': parts[4:4 + nin], 'outs': parts[4 + nin:4 + nin + nout], 'p': params,
        })
    return layers


class Reader:
    def __init__(self, data):
        self.data = data
        self.off = 0

    def weight(self, count):
        tag = struct.unpack_from('<I', self.data, self.off)[0]
        self.off += 4
        if tag == FP16_TAG:
            arr = np.frombuffer(self.data, '<f2', count, self.off).astype(np.float32)
            self.off = (self.off + count * 2 + 3) // 4 * 4
        elif tag == 0:
            arr = np.frombuffer(self.data, '<f4', count, self.off).copy()
            self.off += count * 4
        else:
            raise ValueError('unsupported weight storage %#x' % tag)
        return arr

    def raw(self, count):
        arr = np.frombuffer(self.data, '<f4', count, self.off).copy()
        self.off += count * 4
        return arr


def activation(p):
    kind = int(p.get(9, 0))
    if kind == 0:
        return None
    if kind == 1:
        return {'type': 'relu'}
    if kind == 2:
        return {'type': 'leaky', 'slope': p.get(10, [0.0])[0]}
    if kind == 4:
        return {'type': 'sigmoid'}
    raise ValueError('unsupported activation %d' % kind)


def convert(src, dst):
    layers = parse_param(src + '.param')
    reader = Reader(open(src + '.bin', 'rb').read())
    blobs = []
    offset = 0
    out_layers = []

    def store(arr):
        nonlocal offset
        arr = np.ascontiguousarray(arr, dtype=np.float32)
        entry = {'offset': offset, 'shape': list(arr.shape)}
        blobs.append(arr.ravel())
        offset += arr.size
        return entry

    for layer in layers:
        kind, p = layer['type'], layer['p']
        entry = {'type': kind, 'name': layer['name'], 'ins': layer['ins'], 'outs': layer['outs']}
        if kind == 'Input':
            continue
        if kind in ('Convolution', 'Deconvolution'):
            cout, k = int(p[0]), int(p[1])
            cin = int(p[6]) // (cout * k * k)
            w = reader.weight(int(p[6])).reshape(cout, cin, k, k)
            # conv2d filter: [kh, kw, in, out]; conv2dTranspose filter: [kh, kw, out, in].
            entry['w'] = store(w.transpose(2, 3, 1, 0) if kind == 'Convolution' else w.transpose(2, 3, 0, 1))
            if int(p.get(5, 0)):
                entry['b'] = store(reader.raw(cout))
            entry.update(kernel=k, stride=int(p.get(3, 1)), pad=int(p.get(4, 0)), out=cout, act=activation(p))
        elif kind == 'InnerProduct':
            cout = int(p[0])
            w = reader.weight(int(p[2])).reshape(cout, -1)
            entry['w'] = store(w.T)  # [in, out] for matMul
            if int(p.get(1, 0)):
                entry['b'] = store(reader.raw(cout))
            entry.update(act=activation(p))
        elif kind == 'Pooling':
            if int(p.get(0, 0)) != 1 or int(p.get(4, 0)) != 1:
                raise ValueError('only global average pooling is supported')
        elif kind == 'BinaryOp':
            entry['op'] = {0: 'add', 2: 'mul'}[int(p.get(0, 0))]
        elif kind == 'Crop':
            if [int(a) for a in p[11]] != [-2, -1]:
                raise ValueError('only height/width crops are supported')
            entry['start'] = [int(v) for v in p[9]]
            entry['end'] = [int(v) for v in p[10]]  # negative: from the end
        elif kind != 'Split':
            raise ValueError('unsupported layer ' + kind)
        out_layers.append(entry)

    if reader.off != len(reader.data):
        raise ValueError('weights left over: %d of %d bytes read' % (reader.off, len(reader.data)))
    os.makedirs(dst, exist_ok=True)
    np.concatenate(blobs).astype('<f4').tofile(os.path.join(dst, 'weights.bin'))
    name = os.path.basename(src)
    scale = int(name[2])  # up3x-...
    meta = {
        'name': 'realcugan-' + name, 'scale': scale,
        # realcugan-ncnn-vulkan: context on each side and the size alignment the U-Nets need.
        'prepadding': {2: 18, 3: 14, 4: 19}[scale], 'align': 4 if scale == 3 else 2,
        'layers': out_layers,
    }
    with open(os.path.join(dst, 'model.json'), 'w', encoding='ascii') as fh:
        json.dump(meta, fh, separators=(',', ':'))
    print('%s: %d layers, %d weights' % (dst, len(out_layers), offset))


if __name__ == '__main__':
    if len(sys.argv) != 3:
        sys.exit(__doc__)
    convert(sys.argv[1], sys.argv[2])
