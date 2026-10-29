Real-ESRGAN `realesr-general-x4v3` (SRVGGNetCompact, 4×), BSD 3-Clause (see LICENSE),
by Xintao Wang et al.: https://github.com/xinntao/Real-ESRGAN

Source: https://github.com/xinntao/Real-ESRGAN/releases/download/v0.2.5.0/realesr-general-x4v3.pth
(sha256 8dc7edb9ac80ccdc30c3a5dca6616509367f05fbc184ad95b731f05bece96292)

Converted for TensorFlow.js without PyTorch:

    python3 tools/convert-realesrgan.py realesr-general-x4v3.pth assets/models/realesr-general-x4v3

`weights.bin` holds float32 tensors in `model.json` order: conv kernels as
[kh, kw, in, out], PReLU alphas, and the last conv's channels reordered so that
TensorFlow's `depthToSpace` matches PyTorch's `PixelShuffle`.
