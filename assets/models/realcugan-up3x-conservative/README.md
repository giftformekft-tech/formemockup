Real-CUGAN `up3x-conservative` (UpCunet3x with squeeze-excitation, 3×, conservative
denoise), MIT (see LICENSE), by bilibili: https://github.com/bilibili/ailab/tree/main/Real-CUGAN

Source: the `models-se` ncnn weights of realcugan-ncnn-vulkan by nihui
(https://github.com/nihui/realcugan-ncnn-vulkan), the same files the Design-flow app uses:

- `up3x-conservative.param` sha256 4e8b144dd34e558f530231026d229bedb0eb8877233bf5d9b457bf1bfa4558c1
- `up3x-conservative.bin` sha256 8321c450efedcd94192196b03ddec19c2a023a5637df7208d49e9e0461560dff

Converted for TensorFlow.js, only numpy needed:

    python3 tools/convert-realcugan.py models-se/up3x-conservative assets/models/realcugan-up3x-conservative

`model.json` holds the layer graph (convolutions, transposed convolutions, the SE blocks'
global average pooling and dense layers, crops, adds) with `prepadding` 14 and `align` 4;
`weights.bin` holds float32 tensors: conv kernels as [kh, kw, in, out], transposed conv
kernels as [kh, kw, out, in], dense weights as [in, out].
