"""Prepare a photo for the bill reader: image bytes on stdin, a JPEG on stdout.

Turns iPhone HEIC (and any format Pillow reads) into JPEG, applies the camera's rotation, and
shrinks the long edge to at most MAX_EDGE pixels: Claude downsizes larger images anyway, so a
bigger image only costs more. Runs in the WeasyPrint venv (/opt/pdf), which has Pillow and
pillow-heif. Exit code 2 with a message on stderr when the image can't be read.
"""
import io
import sys

from PIL import Image, ImageOps

MAX_EDGE = 1568

try:
	from pillow_heif import register_heif_opener
	register_heif_opener()
except ImportError:
	pass  # HEIC then fails below with a clear message; other formats still work


def main() -> int:
	data = sys.stdin.buffer.read()
	try:
		img = Image.open(io.BytesIO(data))
		img = ImageOps.exif_transpose(img)
	except Exception as exc:  # noqa: BLE001 - any unreadable image gets the same answer
		print('Could not read the image: ' + str(exc), file=sys.stderr)
		return 2
	if img.mode not in ('RGB', 'L'):
		img = img.convert('RGB')
	img.thumbnail((MAX_EDGE, MAX_EDGE), Image.LANCZOS)
	out = io.BytesIO()
	img.save(out, 'JPEG', quality=88, optimize=True)
	sys.stdout.buffer.write(out.getvalue())
	return 0


if __name__ == '__main__':
	sys.exit(main())
