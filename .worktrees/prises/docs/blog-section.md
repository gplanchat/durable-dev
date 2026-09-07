# docs/blog-section

- **Work item**: the site's first non-docs section. `documentation/user/` holds seventeen sections
  of feature reference plus `use-cases/`, and there is nowhere for a piece that argues rather than
  documents. The first post is the hexagonal reading of the Nexus demonstration: the stub belongs
  in a driven adapter, the port speaks your context's language, and the translation between them is
  the anti-corruption layer.
- **Entry points**: a third `[[module.mounts]]` in `hugo-docs/hugo.toml` mapping
  `../documentation/blog` to `content/posts`, a menu entry under `params.menu.after`, and
  `documentation/blog/{_index.md,nexus-bounded-contexts.md}`. hugo-book already ships `list.html`
  with `book-post` markup, so no layout is written.
- **Careful**: the post's centrepiece is code that is **not** in the repository, and
  `use-cases/nexus-demo.md` says so under "What is not proven". The post owns that in a sentence
  rather than leaving a reader to find it. Everything the demonstration measured is linked, not
  repeated: seven of the draft's nine sections are already published there, and the post is the two
  that are not.
- **English only.** Only `layouts/index.fr.html` is translated; the guide is not duplicated
  under `/fr/`, and a post follows the guide.
- **State**: in progress.
