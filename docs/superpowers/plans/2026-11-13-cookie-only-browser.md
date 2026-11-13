# Cookie-only browser audit implementation plan

Goal: apply the approved cookie-only scope and finish executable consent tests on existing hosting.
Spec: projects/gdpr-cookie-audit/DESIGN.md, approved refinement.

- [ ] Probe/install official runtime in private storage via authorized activation; verify integrity,
  child process execution, browser dependencies and sandbox. Never install through a public endpoint.
- [ ] Add tests excluding privacy-only documents and generic privacy fields; implement cookie-policy
  discovery, cookie-specific evidence and automatic navigation to the cookie document.
- [ ] If runtime is viable, implement bounded scenario worker behind DNS-pinned local proxy: fresh
  visit, reject, accept, categories, withdrawal, persistence, mobile, consent signals and screenshots.
- [ ] Integrate observed scenario results into UI, charts, policy comparison and Czech PDF. Keep
  not-attempted and blocked tests separate from website failures.
- [ ] Review security and measurement correctness, run fixtures and live regression, deploy versioned
  package only to gdpr-cookies.cz, persist source/verification in existing Git branch.

Review focus: private/redirect targets, unsafe cookie-button selectors, leaked cookie values,
mislabeling source hints as runtime proof, privacy-only documents and failed runtime bootstrap.


Execution result: cookie-only scope, UI/PDF changes and source regression completed and deployed.
Browser viability is blocked by the hosting sandbox after private runtime and all observed libraries
were installed. Actual worker integration remains pending this infrastructure prerequisite. No
unverified scenario is presented as completed. See project VERIFICATION.md for evidence and exact status.
