import sys

import trafilatura


def main() -> int:
    html = sys.stdin.read()
    text = trafilatura.extract(
        html,
        include_comments=False,
        include_tables=True,
        output_format="txt",
    )
    if text:
        sys.stdout.write(text)

    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except Exception as error:
        print(error, file=sys.stderr)
        raise SystemExit(1) from error
