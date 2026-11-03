# syntax=docker/dockerfile:1
# Production image for the RevCadence frontend (Next.js).
# Build context = the frontend/ directory (set this service's root dir to "frontend").
FROM node:22-alpine

WORKDIR /app
ENV NEXT_TELEMETRY_DISABLED=1

COPY package.json package-lock.json* ./
# --include=dev so the build has Tailwind/TypeScript even if NODE_ENV=production.
RUN npm ci --include=dev

COPY . .

# NEXT_PUBLIC_* must be present at BUILD time (baked into the client bundle).
# Railway supplies this service variable to the Docker build as an ARG.
ARG NEXT_PUBLIC_API_URL
ENV NEXT_PUBLIC_API_URL=$NEXT_PUBLIC_API_URL

RUN npm run build

ENV NODE_ENV=production
EXPOSE 3000
# Bind explicitly to Railway's $PORT on all interfaces.
CMD ["sh", "-c", "npm start -- -p ${PORT:-3000} -H 0.0.0.0"]
