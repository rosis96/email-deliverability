# syntax=docker/dockerfile:1
FROM node:22-alpine

WORKDIR /app

# Dependencies are installed at runtime via the compose command so that the
# bind-mounted source and the node_modules volume stay in sync across hosts.
COPY frontend/package.json frontend/package-lock.json* ./
RUN npm install || true

COPY frontend/ ./

EXPOSE 3000

CMD ["npm", "run", "dev"]
