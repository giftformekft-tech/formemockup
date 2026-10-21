Real-CUGAN `up2x-conservative` (UpCunet2x with squeeze-excitation, 2×, conservative
denoise), MIT (see LICENSE), by bilibili: https://github.com/bilibili/ailab/tree/main/Real-CUGAN

Used for large generations, where 3× would make the long side longer than 5120 px.
Source: the `models-se` ncnn weights of realcugan-ncnn-vulkan by nihui
(https://github.com/nihui/realcugan-ncnn-vulkan), the same files the Design-flow app uses:

- `up2x-conservative.param` sha256 4b3b5c5710ad1d00503c8243417aa75f13fe497723e8b4952e8b1abfc08f9b84
- `up2x-conservative.bin` sha256 91c72c136e7ff8556323d4449c5ceeff22d9829bf8463a01137cadb8d59b84a0

Converted for TensorFlow.js, only numpy needed:

    python3 tools/convert-realcugan.py models-se/up2x-conservative assets/models/realcugan-up2x-conservative

Same format as `../realcugan-up3x-conservative`, with `prepadding` 18 and `align` 2.
