# Contributing to Domain Monitor

Thank you for your interest in contributing to Domain Monitor! This document provides guidelines and instructions for contributing.

## 🌟 Ways to Contribute

- 🐛 **Report bugs** - Help us identify and fix issues
- 💡 **Suggest features** - Share ideas for improvements
- 📝 **Improve documentation** - Help others understand the project
- 🔧 **Submit code** - Fix bugs or implement features
- 🎨 **Enhance UI/UX** - Improve the user experience
- 🌍 **Add translations** - Make the project accessible to more users
- 🌐 **Add TLD servers** - List RDAP/WHOIS servers for TLDs that IANA does not cover ([how](#-adding-tld-servers))

## 🐛 Reporting Bugs

Before submitting a bug report:

1. **Check existing issues** to avoid duplicates
2. **Update to the latest version** to see if the issue persists
3. **Gather information** about your environment

When reporting a bug, include:

- Clear description of the issue
- Steps to reproduce
- Expected vs actual behavior
- Environment details (OS, PHP version, etc.)
- Error messages and logs
- Screenshots if applicable

Use our [Bug Report Template](.github/ISSUE_TEMPLATE/bug_report.md).

## 💡 Suggesting Features

We welcome feature suggestions! Before submitting:

1. **Check the roadmap** to see if it's already planned
2. **Search existing issues** to avoid duplicates
3. **Consider the project scope** - does it fit?

When suggesting a feature, include:

- Clear description of the feature
- Problem it solves
- Use cases and benefits
- Implementation ideas (if any)

Use our [Feature Request Template](.github/ISSUE_TEMPLATE/feature_request.md).

## 🔧 Code Contributions

### Getting Started

1. **Fork the repository**
   ```bash
   git clone https://github.com/ispgrid/domain-monitor.git
   cd domain-monitor
   ```

2. **Install dependencies**
   ```bash
   composer install
   ```

3. **Set up your development environment**
   - Copy `env.example.txt` to `.env`
   - Configure database settings
   - Run web installer: Visit `http://localhost:8000` (or your local domain)

4. **Create a feature branch**
   ```bash
   git checkout -b feature/your-feature-name
   ```

### Coding Standards

We follow PSR-12 coding standards. Key points:

#### PHP Code Style
- Use 4 spaces for indentation (no tabs)
- Follow PSR-12 naming conventions
- Add type hints for parameters and return types
- Use strict typing: `declare(strict_types=1);`

#### File Organization
- Controllers in `app/Controllers/`
- Models in `app/Models/`
- Services in `app/Services/`
- Views in `app/Views/`

#### Naming Conventions
```php
// Classes: PascalCase
class DomainController {}

// Methods: camelCase
public function getDomainInfo() {}

// Variables: camelCase
$domainName = 'example.com';

// Constants: UPPER_SNAKE_CASE
const MAX_DOMAINS = 1000;

// Database tables: snake_case
domains, notification_groups, notification_logs
```

#### Documentation
```php
/**
 * Get domain information via WHOIS lookup
 *
 * @param string $domain The domain name to lookup
 * @return array|null Domain info or null if lookup fails
 */
public function getDomainInfo(string $domain): ?array
{
    // Implementation
}
```

#### Security
- Always use prepared statements for SQL queries
- Sanitize user input with `htmlspecialchars()`
- Validate and type-check all inputs
- Never expose sensitive data in error messages

### Database Changes

If your contribution includes database changes:

1. **Create a new migration file** in `database/migrations/`
   - Name it: `XXX_descriptive_name.sql` (e.g., `014_add_new_feature.sql`)
   - Use sequential numbering (next available number)
   - Include `IF NOT EXISTS` checks where appropriate

2. **Update `app/Controllers/InstallerController.php`**
   - Add your migration to the `$incrementalMigrations` array
   - Add it to the appropriate version upgrade path

3. **Test the migration** using the web updater at `/install/update`

### Frontend Changes

If modifying views:

- Follow the established design patterns
- Use consistent spacing and styling
- Ensure responsive design (mobile-friendly)
- Test in multiple browsers
- Use semantic HTML
- Keep JavaScript minimal and vanilla (no frameworks)

### Testing

Before submitting:

1. **Test your changes** thoroughly
   - Test in different environments
   - Test edge cases
   - Test with different PHP versions (8.2+)

2. **Check for errors**
   - No PHP warnings or notices
   - No console errors (for UI changes)
   - No SQL errors

3. **Verify functionality**
   - Feature works as expected
   - Doesn't break existing functionality
   - Handles errors gracefully

### Commit Messages

Write clear, descriptive commit messages:

```bash
# Good commit messages
git commit -m "Add RDAP support for .uk domains"
git commit -m "Fix notification duplicate issue on cron"
git commit -m "Update UI design for consistency"

# Bad commit messages
git commit -m "fix bug"
git commit -m "changes"
git commit -m "update"
```

**Format:**
- Use present tense ("Add feature" not "Added feature")
- Be specific and descriptive
- Reference issues when applicable: "Fix #123: Domain refresh error"

### Pull Request Process

1. **Update documentation** if needed
   - README.md for new features
   - CHANGELOG.md for all changes
   - Inline code comments

2. **Create Pull Request**
   - Use a clear title
   - Describe what changed and why
   - Link related issues
   - Add screenshots for UI changes

3. **PR Template**
   ```markdown
   ## Description
   Brief description of changes
   
   ## Type of Change
   - [ ] Bug fix
   - [ ] New feature
   - [ ] Breaking change
   - [ ] Documentation update
   
   ## Testing
   How has this been tested?
   
   ## Checklist
   - [ ] Code follows project style
   - [ ] Self-review completed
   - [ ] Comments added for complex code
   - [ ] Documentation updated
   - [ ] No new warnings
   - [ ] Tests pass
   ```

4. **Respond to feedback**
   - Address review comments
   - Make requested changes
   - Keep the conversation productive

## 🌐 Adding TLD Servers

Domain Monitor gets RDAP and WHOIS servers from IANA. Some registries are not listed there (for example `.iq`), so lookups for their domains fail or come back without an expiry date. `database/tld-servers.json` fills those gaps. You can add a TLD without writing PHP.

```json
"iq": {
    "rdap": ["https://whois.reg.iq/rdap/"],
    "source": "https://whois.reg.iq"
}
```

- The key is the TLD in lowercase without the leading dot, e.g. `iq` or `com.iq`.
- `rdap`: list of `https://` RDAP base URLs. `whois`: a WHOIS hostname, without scheme or port. Give one or both.
- `source`: a link where reviewers can check the server, such as the registry's own page.
- Only add TLDs that IANA has no server for. The app uses an entry only when the TLD Registry has no server for that TLD, and a server an admin sets in the TLD Registry always wins.

Before opening a pull request:

1. Run `php scripts/check-tld-servers.php`. It lists every error and must print `No errors`. The same check runs on pull requests.
2. Add a domain under that TLD in your install and check that it shows an expiry date.
3. In the pull request, name the domain you tested.

## 📝 Documentation

Help improve our documentation:

- Fix typos and unclear explanations
- Add examples and use cases
- Improve installation instructions
- Create tutorials or guides
- Translate documentation

## 🎨 Design Guidelines

When contributing UI/UX changes:

- **Consistency** - Follow existing design patterns
- **Accessibility** - Ensure usability for all users
- **Responsiveness** - Works on all screen sizes
- **Performance** - Optimize images and assets
- **Simplicity** - Keep it clean and intuitive

## 🌍 Translations

We welcome translations! To add a new language:

1. Create language files in appropriate directories
2. Follow existing translation structure
3. Test thoroughly with the interface
4. Ensure all strings are translated

## 📜 Code of Conduct

### Our Standards

- Be respectful and inclusive
- Welcome newcomers
- Accept constructive criticism
- Focus on what's best for the community
- Show empathy towards others

### Unacceptable Behavior

- Harassment or discrimination
- Trolling or insulting comments
- Public or private harassment
- Publishing others' private information
- Other unprofessional conduct

## 🏆 Recognition

Contributors are recognized in:

- CHANGELOG.md for significant contributions
- README.md contributors section
- GitHub contributors page

## 📞 Getting Help

- 🐛 [Issues](https://github.com/ispgrid/domain-monitor/issues) - Report bugs and ask questions
- 📖 [README](README.md) - Documentation

## 📋 Project Priorities

Current focus areas:

1. Bug fixes and stability
2. Performance improvements
3. Documentation
4. New notification channels
5. API development
6. Multi-user support

See [CHANGELOG.md](CHANGELOG.md) for the full roadmap.

---

<div align="center">

**Thank you for contributing to Domain Monitor!** 🚀

Maintained by [ispgrid](https://github.com/ispgrid) • Originally created by [Hosteroid](https://www.hosteroid.uk)

</div>

