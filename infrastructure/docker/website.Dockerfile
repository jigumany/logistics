FROM node:22-bookworm-slim AS build
WORKDIR /source
ENV NEXT_TELEMETRY_DISABLED=1
COPY package*.json ./
COPY apps/platform/package.json apps/platform/package.json
COPY apps/website/package.json apps/website/package.json
COPY packages packages
RUN npm ci
COPY apps/website apps/website
ARG NEXT_PUBLIC_QUOTE_COMPANY=cape-town-movers
ENV NEXT_PUBLIC_QUOTE_COMPANY=$NEXT_PUBLIC_QUOTE_COMPANY
ARG NEXT_PUBLIC_PLATFORM_URL
ENV NEXT_PUBLIC_PLATFORM_URL=$NEXT_PUBLIC_PLATFORM_URL
RUN npm run build --workspace=@logistics/website
FROM node:22-bookworm-slim AS production
WORKDIR /app
ENV NODE_ENV=production NEXT_TELEMETRY_DISABLED=1 HOSTNAME=0.0.0.0 PORT=3000
COPY --from=build --chown=node:node /source/apps/website/.next/standalone ./
COPY --from=build --chown=node:node /source/apps/website/.next/static ./apps/website/.next/static
USER node
EXPOSE 3000
CMD ["node", "apps/website/server.js"]
