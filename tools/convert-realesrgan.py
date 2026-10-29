"""Convert a Real-ESRGAN SRVGGNetCompact .pth (e.g. realesr-general-x4v3) to the
float32 weights.bin + model.json read by assets/js/ai-upscale.js.

Reads the PyTorch zip checkpoint with a restricted unpickler, so neither
PyTorch nor arbitrary pickle code is needed:

    python3 tools/convert-realesrgan.py realesr-general-x4v3.pth assets/models/realesr-general-x4v3
"""
import sys, zipfile, pickle, json, numpy as np, os
pth, out = sys.argv[1], sys.argv[2]
z = zipfile.ZipFile(pth)
names = z.namelist(); root = names[0].split('/')[0]
dtypes = {'FloatStorage': np.float32, 'HalfStorage': np.float16}
class Storage:
    def __init__(self, key, dtype): self.key, self.dtype = key, dtype
def rebuild(storage, offset, size, stride, *a):
    buf = np.frombuffer(z.read(f'{root}/data/{storage.key}'), dtype=storage.dtype)
    return np.lib.stride_tricks.as_strided(buf[offset:], shape=size, strides=[s * buf.itemsize for s in stride]).copy()
class U(pickle.Unpickler):
    def find_class(self, mod, name):
        if name == '_rebuild_tensor_v2': return rebuild
        if name == 'OrderedDict': import collections; return collections.OrderedDict
        if name.endswith('Storage'): return name
        raise pickle.UnpicklingError(f'{mod}.{name}')
    def persistent_load(self, pid):
        _, stype, key, loc, n = pid
        return Storage(key, dtypes[stype if isinstance(stype, str) else stype])
sd = U(__import__('io').BytesIO(z.read(f'{root}/data.pkl'))).load()
sd = sd.get('params', sd.get('params_ema', sd))
keys = list(sd.keys())
# SRVGGNetCompact: body.{i} alternates conv / prelu; last conv -> 3*16 channels then PixelShuffle(4)
convs = [k[:-7] for k in keys if k.endswith('.weight') and sd[k].ndim == 4]
layers, blobs, offset = [], [], 0
def add(name, arr):
    global offset
    a = arr.astype(np.float32); blobs.append(a.tobytes()); layers.append({'name': name, 'shape': list(a.shape), 'offset': offset}); offset += a.size
for idx, k in enumerate(keys):
    v = sd[k]
    if v.ndim == 4:
        w = v.transpose(2, 3, 1, 0)  # [out,in,kh,kw] -> [kh,kw,in,out]
        if k == convs[-1] + '.weight':
            # torch PixelShuffle: ch = c*16 + i*4 + j ; tf depthToSpace: ch = (i*4+j)*3 + c
            perm = [c*16 + s for s in range(16) for c in range(3)]
            w = w[..., perm]
        add(k, w)
    elif k.endswith('.bias'):
        if k[:-5] == convs[-1]:
            v = v[[c*16 + s for s in range(16) for c in range(3)]]
        add(k, v)
    else:
        add(k, v.reshape(-1))  # prelu alpha
os.makedirs(out, exist_ok=True)
open(out + '/weights.bin', 'wb').write(b''.join(blobs))
json.dump({'format': 'srvgg-compact', 'scale': 4, 'layers': layers}, open(out + '/model.json', 'w'))
print('floats', offset, 'bytes', offset * 4)
