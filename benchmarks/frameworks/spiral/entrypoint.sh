#!/bin/sh
set -eu
exec rr serve -c .rr.yaml
