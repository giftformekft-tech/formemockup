waifu2x `upconv_7_anime_style_art_rgb` without denoise (`scale2.0x_model`, 2×), the
Design-flow app's default upscaler. The model is waifu2x by nagadomi (MIT,
https://github.com/nagadomi/waifu2x); the ncnn weights and LICENSE are those of
waifu2x-ncnn-vulkan by nihui (MIT, https://github.com/nihui/waifu2x-ncnn-vulkan), the same
files the Design-flow app bundles:

- `scale2.0x_model.param` sha256 413195ffde05b4d43807792c6c020c916cecdf25dcf002ee83f5e28d5cc246c6
- `scale2.0x_model.bin` sha256 27d880903ff81390a3b4a900b67af3381c465279e34297546c9a58781855f7ef

Converted for TensorFlow.js, only numpy needed:

    python3 tools/convert-waifu2x.py models-upconv_7_anime_style_art_rgb/scale2.0x_model assets/models/waifu2x-upconv7-anime-2x

`model.json` holds six valid 3×3 convolutions (LeakyReLU 0.1) and a 4×4 stride-2 transposed
convolution with `prepadding` 7 (waifu2x-ncnn-vulkan's context, so the output is exactly twice
the tile) and `align` 1; `weights.bin` holds float32 tensors: conv kernels as [kh, kw, in, out],
the transposed conv kernel as [kh, kw, out, in]. 4× is this model run twice, as
`waifu2x-ncnn-vulkan -s 4` does.
