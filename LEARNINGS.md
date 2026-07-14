# Learnings

## CRLF endings break bash scripts and inflate git status (WSL tree edited from Windows)
- Trigger: git status showed ~20 modified files with zero real changes (EOL only); packlink-build.sh failed in WSL with carriage-return errors and turned --no-dev into an unknown option.
- Learning (rule): This tree is edited from Windows over the wsl.localhost share, so files acquire CRLF. Before running any repo .sh in bash, run a CR-stripped copy (tr -d CR piped to bash). When staging a release, add files explicitly by path; never stage everything, since most modified files are CRLF noise.
- Destination: project CLAUDE.md; add a .gitattributes with text=auto eol=lf (and sh eol=lf) to fix at the root.
- Status: new
