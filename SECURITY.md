# Security policy

## Supported versions

| Version | Supported |
|---|---|
| 0.1.x | Yes |

## Reporting a vulnerability

Please do not open a public issue for a security problem. Email
hello@indianopinion.org with:

- a description of the problem and its impact,
- the version and PHP version affected,
- steps or a file that reproduces it.

We acknowledge reports within 3 working days and aim to send an assessment
and a plan within 10 working days. We credit reporters in the release notes
unless you ask us not to.

## Scope notes

- The reader treats every byte of a packed file as untrusted: sizes come
  from the header and are checked against the open handle before any
  allocation. A file that makes the scanner allocate without bound, read out
  of range, or return results from damaged bytes is a security bug.
- Temporary files use unpredictable names and exclusive create, so a local
  user cannot pre-create or symlink them. A way around that is a security
  bug.
- Keeping the data file outside the web root, and the directory's
  permissions, are the operator's responsibility (see the README).
