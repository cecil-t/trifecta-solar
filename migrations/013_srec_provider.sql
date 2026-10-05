-- Who the project's SRECs are sold through (keys in Projects::SREC_PROVIDERS)
ALTER TABLE projects ADD COLUMN srec_provider TEXT;
