"""Convert waifu2x-ncnn-vulkan's upconv_7 model (the Design-flow app's default
upscaler: models-upconv_7_anime_style_art_rgb, no denoise, scale2.0x_model)
to the plugin's browser format, with tools/convert-realcugan.py's converter.

    python3 tools/convert-waifu2x.py models-upconv_7_anime_style_art_rgb/scale2.0x_model assets/models/waifu2x-upconv7-anime-2x

Six valid 3×3 convolutions (LeakyReLU 0.1) and a 4×4 stride-2 deconvolution:
with waifu2x-ncnn-vulkan's 7 px context the output is exactly twice the tile.
Design-flow's 4× is this 2× model run twice.
"""
import importlib.util
import os
import sys

spec = importlib.util.spec_from_file_location('convert_realcugan', os.path.join(os.path.dirname(__file__), 'convert-realcugan.py'))
converter = importlib.util.module_from_spec(spec)
spec.loader.exec_module(converter)

if __name__ == '__main__':
    if len(sys.argv) != 3:
        sys.exit(__doc__)
    converter.convert(sys.argv[1], sys.argv[2], {'name': 'waifu2x-upconv_7_anime_style_art_rgb-scale2', 'scale': 2, 'prepadding': 7, 'align': 1})
