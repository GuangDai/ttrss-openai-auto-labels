# Changelog

All notable changes to the OpenAI Auto Labels TTRSS Plugin will be documented in this file.

## [1.0.1] - 2024-12-31

### Added
- OpenAI API base URL configuration
  - Users can now customize the API endpoint
  - Useful for proxy servers or alternative OpenAI-compatible services
  - Default value: `https://api.openai.com/v1`

- OpenAI model selection
  - Users can choose which model to use for label generation
  - Default model: `gpt-4o-mini`
  - Support for all OpenAI chat completion models

- Configurable maximum label count
  - Users can set how many labels to generate per article
  - Range: 1-10 labels
  - Default value: 5 labels

- Configurable text length limit
  - Users can set maximum text length for analysis
  - Range: 500-4000 characters
  - Default value: 1500 characters

### Changed
- Settings page layout
  - Added new configuration fields for all customizable options
  - Improved descriptions and help text
  - Added numeric spinners for easier value selection
  - Grouped related settings together

### Technical Details
- Added new class variables:
  - `$openai_base_url`
  - `$openai_model`
  - `$max_labels`
  - `$max_text_length`

- Modified initialization process to handle new settings
- Updated API call structure to use configurable values

## [1.0.0] - 2024-12-30
- Initial public release
- Support for automatic article labeling using OpenAI API
- Configurable label language
- ~~Smart color generation for new labels~~
- Existing label reuse
- Comprehensive error handling and logging
